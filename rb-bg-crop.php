<?php
/**
 * Plugin Name: RB BG Crop (no ACF Crop)
 * Description: Cropper.js sobre Imagen (Mobile) en el repeater del Front. Guarda JSON (x,y,w,h,Ow,Oh). Sincroniza Desktop ↔ Mobile duplicando el adjunto seleccionado. Preview 740×1600.
 * Version: 16
 */
if (!defined('ABSPATH')) exit;

/** Nombres ACF */
const RB_REPEATER_NAME  = 'slider_home_slides';
const RB_IMG_DESK_NAME  = 'slider_home_imagen_desk';
const RB_IMG_MOB_NAME   = 'slider_home_imagen_mob';
const RB_CROP_JSON_NAME = 'slider_home_crop_mob';
const RB_ASPECT_W       = 740;
const RB_ASPECT_H       = 1600;

add_action('admin_enqueue_scripts', function($hook){
  if ($hook !== 'post.php' && $hook !== 'post-new.php') return;

  // Cropper
  wp_enqueue_style('cropper-css', 'https://unpkg.com/cropperjs@1.5.13/dist/cropper.min.css', [], '1.5.13');
  wp_enqueue_script('cropper-js',  'https://unpkg.com/cropperjs@1.5.13/dist/cropper.min.js', [], '1.5.13', true);

  // ===== CSS (con nombres sustituidos) =====
  $css = <<<CSS
/* Ocultar thumbnail nativo SOLO en el campo Mobile */
.acf-field[data-name="__MOB__"] .acf-image-uploader .image-wrap{display:none !important;}

.rb-wrap{width:min(340px,100%);margin-top:6px}
.rb-toolbar{display:none;gap:8px;margin:8px 0}
.rb-toolbar.visible{display:flex}
.rb-btn{appearance:none;border:1px solid #cbd5e1;background:#fff;padding:6px 10px;border-radius:6px;cursor:pointer}
.rb-btn.primary{background:#111;color:#fff;border-color:#111}
.rb-preview{display:none;width:100%;aspect-ratio: 740 / 1600;height:auto;background:#111 center / cover no-repeat;border:none;border-radius:0}
.rb-preview.visible{display:block}

.rb-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:100000}
.rb-modal{width:min(92vw,900px);background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.4)}
.rb-modal header{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #eee}
.rb-modal .canvas{max-height:72vh;overflow:auto;background:#111}
.rb-modal img{display:block;max-width:100%;height:auto;margin:0 auto}
.rb-modal footer{display:flex;gap:8px;justify-content:flex-end;padding:10px 14px;border-top:1px solid #eee}

/* Ocultar el subcampo JSON (opcional) */
.acf-field[data-name="__JSON__"]{display:none !important}
CSS;
  $css = str_replace(['__MOB__','__JSON__'], [RB_IMG_MOB_NAME, RB_CROP_JSON_NAME], $css);
  wp_add_inline_style('cropper-css', $css);

  // ===== JS config y script =====
  $cfg = [
    'ASPECT_W' => RB_ASPECT_W,
    'ASPECT_H' => RB_ASPECT_H,
    'rep'      => RB_REPEATER_NAME,
    'desk'     => RB_IMG_DESK_NAME,
    'mob'      => RB_IMG_MOB_NAME,
    'crop'     => RB_CROP_JSON_NAME,
  ];
  wp_add_inline_script('cropper-js', 'window.RB_BG_CROP_CFG = '.wp_json_encode($cfg).';', 'before');

  ob_start(); ?>
(function(){
  function q(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }
  function matches(el, sel){ return (el.matches||el.msMatchesSelector||el.webkitMatchesSelector).call(el, sel); }
  function closest(el, sel){ while(el && el.nodeType===1){ if(matches(el,sel)) return el; el=el.parentNode; } return null; }

  var CFG = window.RB_BG_CROP_CFG||{};
  var NAME_REP  = CFG.rep, NAME_DESK = CFG.desk, NAME_MOB = CFG.mob, NAME_CROP = CFG.crop;
  var ASPECT_W  = CFG.ASPECT_W, ASPECT_H = CFG.ASPECT_H;
  var syncing   = false;

  // --- Helpers (V4 - Robustos) ---
  function getUploader(field){ return field ? field.querySelector('.acf-image-uploader') : null; }
  function getImg(field){ var up=getUploader(field); return up? up.querySelector('img'): null; }
  function hasValue(field){ var up=getUploader(field); return !!(up && up.classList.contains('has-value')); }
  function getFieldId(field){
    var up=getUploader(field); if(!up) return '';
    var input=up.querySelector('input[type="hidden"]');
    return input ? input.value : '';
  }
  function setFieldIdAndThumb(field, id, url){
    var up=getUploader(field); if(!up) return;
    var input=up.querySelector('input[type="hidden"]');
    if (input) {
        input.value = id;
        // Disparar 'change' para que bindHiddenInputWatcher lo escuche
        var ev = new Event('change', { bubbles: true });
        input.dispatchEvent(ev);
    }
    up.classList.add('has-value');
    var wrap=up.querySelector('.image-wrap');
    if(!wrap){ wrap=document.createElement('div'); wrap.className='image-wrap'; up.insertBefore(wrap, up.firstChild); }
    var img=wrap.querySelector('img');
    if(!img){ img=document.createElement('img'); wrap.appendChild(img); }
    img.src = url;
  }
  function findRowFieldByName(row, name){
    var el = row.querySelector('.acf-field[data-name="'+name+'"]');
    if (el) return el;
    var input = row.querySelector('[data-name="'+name+'"]');
    return input ? closest(input, '.acf-field') : null;
  }
  function getCropInputFromField(field){
    var row = closest(field, '.acf-row') || closest(field, '[data-layout]') || field;
    return row.querySelector('.acf-field[data-name="'+NAME_CROP+'"] input, .acf-field[data-name="'+NAME_CROP+'"] textarea');
  }

  // --- Lógica de Preview (V12 - Correcta) ---
  function paintPreview(preview, imgUrl, data){
    if (!preview) return;
    if (!imgUrl){
      preview.classList.remove('visible');
      preview.style.setProperty('background', 'none', 'important'); // Limpiar
      return;
    }
    
    preview.classList.add('visible');
    
    // Usar imagen FULL-RES para el preview
    var fullUrl = imgUrl.replace(/-\d+x\d+(\.(jpe?g|png|gif|webp))$/i, '$1');
    var bgStyle = '#111 url('+fullUrl+') center / cover no-repeat';

    if (data && data.w && data.h && data.Ow && data.Oh && data.w !== 0 && data.h !== 0 && data.Ow !== 0 && data.Oh !== 0){
      try {
        var d = data;
        var sizeX = (d.Ow / d.w) * 100;
        var sizeY = (d.Oh / d.h) * 100;
        var posX = (d.x / (d.Ow - d.w)) * 100;
        var posY = (d.y / (d.Oh - d.h)) * 100;
        if (isNaN(posX) || !isFinite(posX)) posX = 0;
        if (isNaN(posY) || !isFinite(posY)) posY = 0;
        bgStyle = '#111 url('+fullUrl+') ' +
                  posX.toFixed(5) + '% ' +
                  posY.toFixed(5) + '% / ' +
                  sizeX.toFixed(5) + '% ' +
                  sizeY.toFixed(5) + '% no-repeat';
      } catch(e) {
        bgStyle = '#111 url('+fullUrl+') center / cover no-repeat';
      }
    }
    preview.style.setProperty('background', bgStyle, 'important');
  }

  function refreshMobileUI(field){
    var up=getUploader(field);
    var wrap=field.querySelector('[data-rb-wrap]') || field.parentNode.querySelector('[data-rb-wrap]');
    var toolbar = wrap ? wrap.querySelector('.rb-toolbar') : null;
    var preview = wrap ? wrap.querySelector('.rb-preview') : null;
    var cropInput = getCropInputFromField(field);
    var img = getImg(field);

    if (hasValue(field) && img && img.getAttribute('src')){
      if (toolbar) toolbar.classList.add('visible');
      var data = null;
      if (cropInput && cropInput.value){ try{ data = JSON.parse(cropInput.value); }catch(e){} }
      paintPreview(preview, img.getAttribute('src'), data);
    } else {
      if (toolbar) toolbar.classList.remove('visible');
      paintPreview(preview, null, null);
    }
  }

  // --- Lógica del modal (V13 - Correcta) ---
  function openCropModal(field, preview){
    var img = getImg(field);
    if (!img || !img.getAttribute('src')){ alert('Seleccioná una imagen primero.'); return; }

    var bd = document.createElement('div');
    bd.className='rb-backdrop';
    bd.innerHTML =
      '<div class="rb-modal">'+
        '<header><strong>Elegir encuadre ('+ASPECT_W+'×'+ASPECT_H+')</strong><button type="button" class="rb-btn" data-close>✕</button></header>'+
        '<div class="canvas"><img data-canvas></div>'+
        '<footer><button type="button" class="rb-btn" data-reset>Reiniciar</button><button type="button" class="rb-btn primary" data-apply>Usar encuadre</button></footer>'+
      '</div>';
    document.body.appendChild(bd);
    bd.style.display='flex';

    var can = bd.querySelector('[data-canvas]');
    var thumbUrl = img.getAttribute('src');
    var fullUrl = thumbUrl.replace(/-\d+x\d+(\.(jpe?g|png|gif|webp))$/i, '$1');
    can.src = fullUrl;
    var cropper=null;

    function init(){
      cropper = new Cropper(can, {
        viewMode: 1, responsive: true, aspectRatio: ASPECT_W / ASPECT_H,
        autoCropArea: 0.8,
        dragMode: 'crop', 
        movable: false,   
        zoomable: false,
        scalable: false,
        rotatable: false,
        ready: function(){
          try {
            var imageData = cropper.getImageData(); var natW = imageData.naturalWidth; var natH = imageData.naturalHeight;
            var container = cropper.getContainerData();
            var ratio = Math.min(container.width / natW, container.height / natH);
            if (ratio && isFinite(ratio) && ratio > 0) cropper.zoomTo(ratio);
            var cropInput = getCropInputFromField(field);
            if (cropInput && cropInput.value){
              try{
                var d = JSON.parse(cropInput.value);
                if (d.Ow === natW && d.Oh === natH) { cropper.setData(d); }
              }catch(e){}
            }
          } catch(e){}
        }
      });
    }
    if (can.complete) init(); else can.addEventListener('load', init, {once:true});

    bd.querySelector('[data-close]').onclick = function(){ if(cropper){cropper.destroy();} bd.remove(); };
    bd.querySelector('[data-reset]').onclick = function(){ if(cropper){cropper.reset();} };
    
    bd.querySelector('[data-apply]').onclick = function(){
      var d = cropper.getData(true); 
      var imgData = cropper.getImageData();
      var natW = imgData.naturalWidth; var natH = imgData.naturalHeight;
      var data = { x:d.x, y:d.y, w:d.width, h:d.height, Ow:natW, Oh:natH };
      var cropInput = getCropInputFromField(field);
      if (cropInput) cropInput.value = JSON.stringify(data);
      cropper.destroy(); bd.remove();
      refreshMobileUI(field); 
    };
  }

  function ensureUIForMobileField(field){
    if (field.getAttribute('data-name') !== NAME_MOB) return;
    if (field.querySelector('[data-rb-wrap]')) return;

    var up = getUploader(field);
    var wrap = document.createElement('div');
    wrap.className='rb-wrap'; wrap.setAttribute('data-rb-wrap','');
    var toolbar = document.createElement('div');
    toolbar.className='rb-toolbar';
    toolbar.innerHTML =
      '<button type="button" class="rb-btn primary" data-open>Elegir encuadre</button>'+
      '<button type="button" class="rb-btn" data-change>Cambiar imagen</button>'+
      '<button type="button" class="rb-btn" data-clear>Limpiar</button>';
    var preview = document.createElement('div');
    preview.className='rb-preview';

    if (up) up.insertAdjacentElement('afterend', wrap); else field.appendChild(wrap);
    wrap.appendChild(toolbar);
    wrap.appendChild(preview);

    toolbar.querySelector('[data-open]').addEventListener('click', function(){
      var currentPreview = wrap.querySelector('.rb-preview');
      openCropModal(field, currentPreview); 
    });
    toolbar.querySelector('[data-change]').addEventListener('click', function(){
      var currentUp = getUploader(field);
      var removeBtn = currentUp ? currentUp.querySelector('.acf-button[data-name="remove"]') : null;
      if (removeBtn){ removeBtn.click(); }
      setTimeout(function(){
        var addBtn = currentUp ? currentUp.querySelector('.acf-button[data-name="add"]') : null;
        if (addBtn) addBtn.click();
      }, 80);
    });
    toolbar.querySelector('[data-clear]').addEventListener('click', function(){
      var currentUp = getUploader(field);
      var currentCropInput = getCropInputFromField(field);
      if (currentCropInput) currentCropInput.value='';
      var removeBtn = currentUp ? currentUp.querySelector('.acf-button[data-name="remove"]') : null;
      if (removeBtn) removeBtn.click();
    });

    refreshMobileUI(field);
    if (up){
      var obs = new MutationObserver(function(){ refreshMobileUI(field); });
      obs.observe(up, { attributes:true, attributeFilter:['class'] });
    }
  }

  // --- Lógica de Sincronización (V6 - Correcta) ---
  function syncPair(row, srcName, dstName){
    if (syncing) return;
    var src = findRowFieldByName(row, srcName);
    var dst = findRowFieldByName(row, dstName);
    if (!src || !dst) return;

    var id = getFieldId(src);
    var dstId = getFieldId(dst);
    
    if (!id) { // Sincronizar BORRADO
      if (dstId) { 
        syncing = true;
        var up=getUploader(dst);
        var removeBtn = up ? up.querySelector('.acf-button[data-name="remove"]') : null;
        if (removeBtn) removeBtn.click();
        if (dst.getAttribute('data-name') === NAME_MOB) {
            var cropInput = getCropInputFromField(dst);
            if (cropInput) cropInput.value='';
        }
        syncing = false;
      }
      return;
    }
    
    if (id === dstId) return; // Ya están sincronizados

    // Sincronizar AÑADIDO/CAMBIO
    var srcImg = getImg(src);
    var url = srcImg ? srcImg.getAttribute('src') : '';
    if (!url) return;

    syncing = true;
    setFieldIdAndThumb(dst, id, url);
    if (dst.getAttribute('data-name') === NAME_MOB) {
        var cropInput = getCropInputFromField(dst);
        if (cropInput) cropInput.value=''; // Limpiar crop anterior
    }
    syncing = false;
  }

  // ==========================================================
  // FIX V16: RESTAURAR EL bindHiddenInputWatcher
  // ==========================================================
  
  // 1. Añadir la función (del V1)
  function bindHiddenInputWatcher(field, cb){
    var up=getUploader(field); if(!up) return null;
    var hid = up.querySelector('input[type="hidden"]');
    if (!hid) return;
    // 'change' es el evento que disparamos en setFieldIdAndThumb
    hid.addEventListener('change', cb);
  }

  function setupRow(row){
    if (row.getAttribute('data-rb-setup')) return;
    row.setAttribute('data-rb-setup', 'true');

    var mobField  = findRowFieldByName(row, NAME_MOB);
    var deskField = findRowFieldByName(row, NAME_DESK);

    if (mobField) ensureUIForMobileField(mobField);

    // 2. Restaurar la lógica de "doble-escucha"
    
    // Desktop → Mobile
    if (deskField){
        // A) Escuchar el cambio de ID (para 'add')
        bindHiddenInputWatcher(deskField, function(){ syncPair(row, NAME_DESK, NAME_MOB); });
        
        // B) Escuchar el cambio de clase (para 'remove')
        var upD = getUploader(deskField);
        if(upD){
            var obsD = new MutationObserver(function(mutations){
                var oldValue = mutations[0].oldValue || "";
                var was = oldValue.includes('has-value');
                var is = upD.classList.contains('has-value');
                if (was && !is) { // Si 'has-value' fue removido
                   syncPair(row, NAME_DESK, NAME_MOB);
                }
            });
            obsD.observe(upD, { attributes:true, attributeFilter:['class'], attributeOldValue:true });
        }
    }

    // Mobile → Desktop
    if (mobField){
        // A) Escuchar el cambio de ID (para 'add')
        bindHiddenInputWatcher(mobField, function(){ syncPair(row, NAME_MOB, NAME_DESK); });
        
        // B) Escuchar el cambio de clase (para 'remove')
        var upM = getUploader(mobField);
        if(upM){
            var obsM = new MutationObserver(function(mutations){
                var oldValue = mutations[0].oldValue || "";
                var was = oldValue.includes('has-value');
                var is = upM.classList.contains('has-value');
                if (was && !is) { // Si 'has-value' fue removido
                  syncPair(row, NAME_MOB, NAME_DESK);
                }
            });
            obsM.observe(upM, { attributes:true, attributeFilter:['class'], attributeOldValue:true });
        }
    }
  }

  function bootAll(){
    qa('.acf-field-repeater[data-name="'+NAME_REP+'"] .acf-row').forEach(setupRow);
  }

  if (window.acf){
    acf.addAction('ready', bootAll);
    
    // 3. Mantener el setTimeout de 500ms (V15)
    acf.addAction('append', function( $el ){
      var $row = $el.is('.acf-row') ? $el : $el.closest('.acf-row');
      if ($row.length) {
        setTimeout(function() {
          setupRow($row[0]);
        }, 500); 
      }
    });
    
  } else {
    document.addEventListener('DOMContentLoaded', bootAll);
  }

  // Recalcular preview al resize
  window.addEventListener('resize', function(){
    qa('.acf-field[data-name="'+NAME_MOB+'"]').forEach(function(f){
      refreshMobileUI(f);
    });
  });
})();
<?php
  $js = ob_get_clean();
  wp_add_inline_script('cropper-js', $js, 'after');
});
