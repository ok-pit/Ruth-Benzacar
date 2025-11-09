<?php
/**
 * Plugin Name: RB BG Crop (no ACF Crop)
 * Description: Cropper.js sobre Imagen (Mobile) en el repeater del Front. Guarda JSON (x,y,w,h,Ow,Oh). Sincroniza Desktop ↔ Mobile duplicando el adjunto seleccionado. Preview 740×1600.
 * Version: 24
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

  // ==========================================================
  // HELPERS V18 (LOS CORRECTOS, USANDO V1)
  // ==========================================================
  function getUploader(field){ return field ? field.querySelector('.acf-image-uploader') : null; }
  function getImg(field){ var up=getUploader(field); return up? up.querySelector('img'): null; }
  function hasValue(field){ var up=getUploader(field); return !!(up && up.classList.contains('has-value')); }
  
  function getFieldId(field){
    var up=getUploader(field); if(!up) return '';
    var input=up.querySelector('input[type="hidden"][name$="[id]"]'); // V1 Selector
    return input ? input.value : '';
  }
  function setFieldIdAndThumb(field, id, url){
    var up=getUploader(field); if(!up) return;
    var input=up.querySelector('input[type="hidden"][name$="[id]"]'); // V1 Selector
    if (input) {
        input.value = id;
        var ev = new Event('change', { bubbles: true }); // Disparamos 'change'
        input.dispatchEvent(ev);
    }
    up.classList.add('has-value');
    var wrap=up.querySelector('.image-wrap');
    if(!wrap){ wrap=document.createElement('div'); wrap.className='image-wrap'; up.insertBefore(wrap, up.firstChild); }
    var img=wrap.querySelector('img');
    if(!img){ img=document.createElement('img'); wrap.appendChild(img); }
    img.src = url;
  }
  function hiddenIdInput(field){
    var up=getUploader(field); if(!up) return null;
    return up.querySelector('input[type="hidden"][name$="[id]"]'); // V1 Selector
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

  // --- Lógica de Preview (V13 - Correcta) ---
  function paintPreview(preview, imgUrl, data){
    if (!preview) return;
    if (!imgUrl){
      preview.classList.remove('visible');
      preview.style.setProperty('background', 'none', 'important');
      return;
    }
    preview.classList.add('visible');
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
      } catch(e) { /* default ya está seteado */ }
    }
    preview.style.setProperty('background', bgStyle, 'important');
  }

  // --- Lógica de UI (V13 - Correcta, PERO CON OBS V1) ---
  function ensureUIForMobileField(field){
    if (field.getAttribute('data-name') !== NAME_MOB) return;
    if (field.querySelector('[data-rb-wrap]')) {
        refreshMobileUI(field); // V23: Si ya existe, solo refresca
        return;
    }
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
    
    // Este es el Observer para el *preview*
    if (up){
      var obs = new MutationObserver(function(){ refreshMobileUI(field); });
      obs.observe(up, { attributes:true, attributeFilter:['class'] });
    }
  }

  // --- Lógica de Sync (V19 - Correcta) ---
  function syncPair(row, srcName, dstName){
    if (syncing) return;
    var src = findRowFieldByName(row, srcName);
    var dst = findRowFieldByName(row, dstName);
    if (!src || !dst) return;

    var id = getFieldId(src);
    var dstId = getFieldId(dst);
    
    // Lógica de BORRADO
    if (!id) {
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

    // Lógica de AÑADIDO
    var srcImg = getImg(src);
    var url = srcImg ? srcImg.getAttribute('src') : '';
    if (!url) return;

    syncing = true;
    setFieldIdAndThumb(dst, id, url);
    if (dst.getAttribute('data-name') === NAME_MOB) {
        var cropInput = getCropInputFromField(dst);
        if (cropInput) cropInput.value=''; // Limpiar crop anterior
        refreshMobileUI(dst);
    }
    syncing = false;
  }

  // --- Lógica de Bindeo V20 (Correcta) ---
  function bindHiddenInputWatcher(field, cb){
    var hid = hiddenIdInput(field);
    if (!hid) return;
    // V1
    ['input','change'].forEach(function(ev){ hid.addEventListener(ev, cb); });
  }

  // ==========================================================
  // FIX V24: RESTAURAR EL setupRow COMPLETO (DE V1)
  // ==========================================================
  function setupRow(row){
    // V24: Guard para prevenir re-bindeos exponenciales
    if (row.getAttribute('data-rb-setup-v24')) return;
    row.setAttribute('data-rb-setup-v24', 'true');
    
    var mobField  = findRowFieldByName(row, NAME_MOB);
    var deskField = findRowFieldByName(row, NAME_DESK);

    if (mobField) ensureUIForMobileField(mobField);

    // Desktop → Mobile (Lógica V1)
    if (deskField){
      var upD = getUploader(deskField);
      if (upD){
        var obsD = new MutationObserver(function(){ syncPair(row, NAME_DESK, NAME_MOB); });
        obsD.observe(upD, { attributes:true, attributeFilter:['class'] });
      }
      bindHiddenInputWatcher(deskField, function(){ syncPair(row, NAME_DESK, NAME_MOB); });
    }

    // Mobile → Desktop (Lógica V1)
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

  // ==========================================================
  // FIX V24: USAR LA LÓGICA DE BOOT V23 (CON TIMEOUT EN 'append')
  // ==========================================================
  if (window.acf){
    // 1. Correr en 'ready' para filas existentes (Lógica V1)
    acf.addAction('ready', bootAll);
    
    // 2. Correr en 'append' para filas nuevas (Lógica V15)
    acf.addAction('append', function( $el ){
      var $row = $el.is('.acf-row') ? $el : $el.closest('.acf-row');
      if ($row.length) {
        // Esperar 500ms a que ACF termine de renderizar los sub-campos
        setTimeout(function() {
          setupRow($row[0]);
        }, 500); 
      }
    });
    
  } else {
    document.addEventListener('DOMContentLoaded', bootAll);
  }

  // Lógica V13 (Mejorada)
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
