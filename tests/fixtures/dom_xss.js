const el = document.getElementById('messages');
const message = { content: '<img src=x onerror=alert(1)>' };
el.innerHTML = `<div class="body">${message.content}</div>`;
el.innerHTML = '';
