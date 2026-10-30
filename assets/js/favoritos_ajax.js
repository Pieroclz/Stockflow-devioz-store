document.addEventListener('submit', async (event) => {
  const form = event.target.closest('form[data-ajax-favorite]');
  if (!form) return;
  event.preventDefault();
  const button = form.querySelector('button[type="submit"]');
  if (!button || button.disabled) return;
  const previous = button.textContent;
  button.disabled = true;
  try {
    const response = await fetch(form.action, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    });
    if (!response.ok) throw new Error(response.status === 401 ? 'Inicia sesión para guardar favoritos' : 'No se pudo guardar el favorito');
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'No se pudo guardar el favorito');
    button.textContent = data.favorite ? '♥' : '♡';
    button.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
    button.setAttribute('aria-label', data.favorite ? 'Quitar de favoritos' : 'Guardar en favoritos');
    button.classList.toggle('is-favorite', Boolean(data.favorite));
    showFavoriteNotice(data.favorite ? '♥ Guardado en tus favoritos' : 'Favorito eliminado');
  } catch (error) {
    button.textContent = previous;
    showFavoriteNotice(error.message || 'Error de conexión');
  } finally { button.disabled = false; }
});
let favoriteNoticeTimeout;
function showFavoriteNotice(message) {
  const notice = document.getElementById('favoriteNotice');
  if (!notice) return;
  notice.textContent = message; notice.hidden = false;
  clearTimeout(favoriteNoticeTimeout);
  favoriteNoticeTimeout = setTimeout(() => { notice.hidden = true; }, 2800);
}
