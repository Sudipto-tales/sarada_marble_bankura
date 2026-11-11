(() => {
  'use strict';
  const config = JSON.parse(document.querySelector('#visualizer-config').textContent);
  const main = document.querySelector('#visualizer-workspace');
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const toast = message => {const element=document.querySelector('#tool-status');if(element)element.textContent=message;};
  if (config.mode === 'saved') { window.RoomPhotoEditor.savedCards(main, escape); return; }
  window.RoomPhotoEditor.mount({main, products:config.materials, rooms:config.rooms, params:new URLSearchParams(location.search), escape, toast}).catch(error => {main.textContent=error.message || 'The visualizer could not start. Try a browser with WebGL enabled.';});
})();
