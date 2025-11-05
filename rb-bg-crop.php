<?php
/**
 * Plugin Name: RB BG Crop (no ACF Crop)
 * Description: Cropper.js sobre Imagen (Mobile) en el repeater del Front. Guarda JSON (x,y,w,h,Ow,Oh). Sincroniza Desktop ↔ Mobile duplicando el adjunto seleccionado. Preview 740×1600.
 * Version: 3.1.0
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

  function getUploader(field){ return field ? field.querySelector('.acf-image-uploader') : null; }
  function getImg(field){ var up=getUploader(field); return up? up.querySelector('img'): null; }
  function hasValue(field){ var up=getUploader(field); return !!(up && up.classList.contains('has-value')); }
  function getFieldId(field){
    var up=getUploader(field); if(!up) return '';
    var input=up.querySelector('input[type="hidden"][name$="[id]"]');
    return input ? input.value : '';
  }
  function setFieldIdAndThumb(field, id, url){
    var up=getUploader(field); if(!up) return;
    var input=up.querySelector('input[type="hidden"][name$="[id]"]');
    if (input) input.value = id;
    up.classList.add('has-value');

    var wrap=up.querySelector('.image-wrap');
    if(!wrap){ wrap=document.createElement('div'); wrap.className='image-wrap'; up.insertBefore(wrap, up.firstChild); }
    var img=wrap.querySelector('img');
    if(!img){ img=document.createElement('img'); wrap.appendChild(img); }
    img.src = url;
  }
  function hiddenIdInput(field){
    var up=getUploader(field); if(!up) return null;
    return up.querySelector('input[type="hidden"][name$="[id]"]');
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

  function paintPreview(preview, imgUrl, data){
    if (!preview) return;
    if (!imgUrl){ preview.classList.remove('visible'); preview.style.backgroundImage='none'; return; }
    preview.classList.add('visible');
    preview.style.backgroundImage = 'url('+imgUrl+')';
    if (!data){ preview.style.backgroundSize='cover'; preview.style.backgroundPosition='center'; return; }
    var contW = preview.clientWidth || 300;
    var scale = contW / data.w;
    var bgW = Math.round(data.Ow * scale);
    var bgH = Math.round(data.Oh * scale);
    var posX = Math.round(-data.x * scale);
    var posY = Math.round(-data.y * scale);
    preview.style.backgroundSize = bgW+'px '+bgH+'px';
    preview.style.backgroundPosition = posX+'px '+posY+'px';
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
    can.src = img.getAttribute('src');
    var cropper=null;

    function init(){
      cropper = new Cropper(can, {
        viewMode: 1,
        responsive: true,
        aspectRatio: ASPECT_W / ASPECT_H,
        autoCropArea: 0.8,
        dragMode: 'move',
        zoomable: true,
        movable: true,
        scalable: false,
        rotatable: false,
        ready: function(){
          try {
            // 1) Mostrar imagen COMPLETA (contain)
            var imageData = cropper.getImageData();
            var container = cropper.getContainerData();
            var ratio = Math.min(container.width / imageData.naturalWidth, container.height / imageData.naturalHeight);
            if (ratio && isFinite(ratio) && ratio > 0) cropper.zoomTo(ratio);

            // 2) Si hay JSON previo, restaurarlo en coordenadas nativas
            var cropInput = getCropInputFromField(field);
            if (cropInput && cropInput.value){
              try{
                var d = JSON.parse(cropInput.value);
                var natW = can.naturalWidth || can.width;
                var natH = can.naturalHeight|| can.height;
                var sx = natW / d.Ow, sy = natH / d.Oh;
                cropper.setData({ x:d.x*sx, y:d.y*sy, width:d.w*sx, height:d.h*sy });
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
      var natW = can.naturalWidth || can.width;
      var natH = can.naturalHeight|| can.height;
      var d = cropper.getData(true);
      var data = { x:Math.round(d.x), y:Math.round(d.y), w:Math.round(d.width), h:Math.round(d.height), Ow:natW, Oh:natH };
      var cropInput = getCropInputFromField(field);
      if (cropInput) cropInput.value = JSON.stringify(data);
      paintPreview(preview, img.getAttribute('src'), data);
      cropper.destroy(); bd.remove();
    };
  }

  // ====== UI Mobile (botonera + preview) ======
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

    toolbar.querySelector('[data-open]').addEventListener('click', function(){ openCropModal(field, preview); });

    toolbar.querySelector('[data-change]').addEventListener('click', function(){
      // Forzar selección: quitar si hay, luego abrir "add"
      var removeBtn = up ? up.querySelector('.acf-button[data-name="remove"]') : null;
      if (removeBtn){ removeBtn.click(); }
      setTimeout(function(){
        var addBtn = up ? up.querySelector('.acf-button[data-name="add"]') : null;
        if (addBtn) addBtn.click();
      }, 80);
    });

    toolbar.querySelector('[data-clear]').addEventListener('click', function(){
      var cropInput = getCropInputFromField(field);
      if (cropInput) cropInput.value='';
      var removeBtn = up ? up.querySelector('.acf-button[data-name="remove"]') : null;
      if (removeBtn) removeBtn.click();
      refreshMobileUI(field);
    });

    refreshMobileUI(field);

    // Observar cambios visuales (has-value) para refrescar preview
    if (up){
      var obs = new MutationObserver(function(){ refreshMobileUI(field); });
      obs.observe(up, { attributes:true, attributeFilter:['class'] });
    }
  }

  // ====== Sincronización bi-direccional ======
  function syncPair(row, srcName, dstName){
    if (syncing) return;
    var src = findRowFieldByName(row, srcName);
    var dst = findRowFieldByName(row, dstName);
    if (!src || !dst) return;

    var id = getFieldId(src);
    if (!id) return;

    var srcImg = getImg(src);
    var url = srcImg ? srcImg.getAttribute('src') : '';
    if (!url) return;

    syncing = true;
    setFieldIdAndThumb(dst, id, url);
    if (dst.getAttribute('data-name') === NAME_MOB) refreshMobileUI(dst);
    syncing = false;
  }

  function bindHiddenInputWatcher(field, cb){
    var hid = hiddenIdInput(field);
    if (!hid) return;
    // Algunos ACF disparan 'input', otros 'change'
    ['input','change'].forEach(function(ev){ hid.addEventListener(ev, cb); });
  }

  function setupRow(row){
    var mobField  = findRowFieldByName(row, NAME_MOB);
    var deskField = findRowFieldByName(row, NAME_DESK);

    if (mobField) ensureUIForMobileField(mobField);

    // Desktop → Mobile
    if (deskField){
      var upD = getUploader(deskField);
      if (upD){
        var obsD = new MutationObserver(function(){ syncPair(row, NAME_DESK, NAME_MOB); });
        obsD.observe(upD, { attributes:true, attributeFilter:['class'] });
      }
      bindHiddenInputWatcher(deskField, function(){ syncPair(row, NAME_DESK, NAME_MOB); });
    }

    // Mobile → Desktop
    if (mobField){
      var upM = getUploader(mobField);
      if (upM){
        var obsM = new MutationObserver(function(){ syncPair(row, NAME_MOB, NAME_DESK); });
        obsM.observe(upM, { attributes:true, attributeFilter:['class'] });
      }
      bindHiddenInputWatcher(mobField, function(){ syncPair(row, NAME_MOB, NAME_DESK); });
    }
  }

  function bootAll(){
    qa('.acf-field-repeater[data-name="'+NAME_REP+'"] .acf-row').forEach(setupRow);
  }

  if (window.acf){
    acf.addAction('ready', bootAll);
    acf.addAction('append', function(){ bootAll(); });
  } else {
    document.addEventListener('DOMContentLoaded', bootAll);
  }

  // Recalcular preview al resize
  window.addEventListener('resize', function(){
    qa('.acf-field[data-name="'+NAME_MOB+'"]').forEach(function(f){
      var wrap=f.querySelector('[data-rb-wrap]') || f.parentNode.querySelector('[data-rb-wrap]');
      var preview = wrap ? wrap.querySelector('.rb-preview') : null;
      var cropInput = getCropInputFromField(f);
      var img = getImg(f);
      if (preview && img && img.getAttribute('src')){
        var data=null; if (cropInput && cropInput.value){ try{ data=JSON.parse(cropInput.value);}catch(e){} }
        paintPreview(preview, img.getAttribute('src'), data);
      }
    });
  });
})();
<?php
  $js = ob_get_clean();
  wp_add_inline_script('cropper-js', $js, 'after');
});
