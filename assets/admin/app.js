document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('[data-action]').forEach(el=>el.addEventListener('click',()=>console.log('PlusChat admin action',el.dataset.action)));});
