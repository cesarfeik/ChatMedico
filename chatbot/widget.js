/**
 * PLADIEX — Widget Asistente Clínico (autenticado)
 * Integración (en una página donde el doctor ya inició sesión con Supabase):
 *   <script src="/chatbot/widget.js" data-api="/api/chat.php"></script>
 * La página host debe exponer el token de sesión del doctor:
 *   window.PLX_AUTH_TOKEN = '<access_token>'      // o
 *   window.PLX_GET_TOKEN = async () => '<token>'  // (se relee antes de cada envío)
 */
(function () {
  'use strict';

  const scriptTag = document.currentScript;
  const API_URL = scriptTag?.dataset?.api || '/api/chat.php';

  let isOpen = false;
  let quickPanelOpen = true;
  let history = [];
  let isLoading = false;

  // Ejemplos de consulta (todos requieren un ID de paciente)
  const QUICK_OPTIONS = [
    { label: 'ESTUDIOS DE SANGRE', value: 'Muéstrame los estudios de sangre del paciente PAC-' },
    { label: 'ÚLTIMO DIAGNÓSTICO', value: 'Resume el último diagnóstico registrado del paciente PAC-' },
    { label: 'MEDICAMENTOS', value: 'Qué medicamentos aparecen en el expediente del paciente PAC-' },
    { label: 'ALERGIAS', value: 'Tiene alergias registradas el paciente PAC-' },
  ];

  // ── Poppins ───────────────────────────────────────────────────────────────
  if (!document.querySelector('link[href*="Poppins"]')) {
    const f = document.createElement('link');
    f.rel = 'stylesheet';
    f.href = 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap';
    document.head.appendChild(f);
  }

  // ── CSS ────────────────────────────────────────────────────────────────────
  const cssLink = document.createElement('link');
  cssLink.rel = 'stylesheet';
  cssLink.href = scriptTag ? scriptTag.src.replace('widget.js', 'widget.css') : '/chatbot/widget.css';
  document.head.appendChild(cssLink);

  async function getToken() {
    if (typeof window.PLX_GET_TOKEN === 'function') {
      try { return await window.PLX_GET_TOKEN(); } catch { return null; }
    }
    return window.PLX_AUTH_TOKEN || null;
  }

  // ── DOM ──────────────────────────────────────────────────────────────────────
  function buildWidget() {
    const btn = document.createElement('button');
    btn.id = 'plx-chat-btn';
    btn.className = 'plx-chat-btn';
    btn.setAttribute('aria-label', 'Abrir Asistente Clínico');
    btn.innerHTML = `
      <svg class="plx-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <svg class="plx-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:none">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
      <span class="plx-badge" id="plx-badge" style="display:none">1</span>`;

    const panel = document.createElement('div');
    panel.id = 'plx-chat-panel';
    panel.className = 'plx-chat-panel plx-hidden';
    panel.setAttribute('role', 'dialog');
    panel.innerHTML = `
      <div class="plx-header">
        <div class="plx-header-left">
          <div class="plx-avatar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
              <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>
            </svg>
          </div>
          <div>
            <div class="plx-header-name">Asistente Clínico</div>
            <div class="plx-header-sub"><span class="plx-dot"></span> EXPEDIENTES · PLADIEX</div>
          </div>
        </div>
        <div class="plx-header-actions">
          <button class="plx-hdr-btn" id="plx-clear-btn" title="Reiniciar conversación">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15">
              <polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/>
              <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
            </svg>
          </button>
          <button class="plx-hdr-btn" id="plx-close-btn" title="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="15" height="15">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="plx-messages" id="plx-messages"></div>

      <div class="plx-quick-section" id="plx-quick-section">
        <div class="plx-quick-label">CONSULTAS RÁPIDAS</div>
        <div class="plx-quick-chips" id="plx-quick-chips"></div>
      </div>

      <div class="plx-input-area">
        <button class="plx-toggle-quick" id="plx-toggle-quick" title="Mostrar/ocultar sugerencias">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
            <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
        </button>
        <input type="text" id="plx-input" class="plx-input" placeholder="Escribe el ID del paciente y tu consulta..." maxlength="500" autocomplete="off" />
        <button class="plx-send-btn" id="plx-send-btn" aria-label="Enviar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </div>
      <div class="plx-footer-note">Apoyo informativo · No reemplaza el juicio clínico</div>`;

    document.body.appendChild(btn);
    document.body.appendChild(panel);

    btn.addEventListener('click', toggleChat);
    document.getElementById('plx-close-btn').addEventListener('click', toggleChat);
    document.getElementById('plx-clear-btn').addEventListener('click', clearChat);
    document.getElementById('plx-toggle-quick').addEventListener('click', toggleQuickPanel);
    document.getElementById('plx-send-btn').addEventListener('click', handleSend);
    document.getElementById('plx-input').addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
    });

    renderQuickChips();
    addBotMessage('Hola, soy tu **Asistente Clínico**. Puedo consultar el expediente de tus pacientes asignados. Indícame el **ID del paciente** (ej. PAC-00123) y qué necesitas revisar.', []);
    document.getElementById('plx-badge').style.display = 'flex';
  }

  function toggleChat() {
    isOpen = !isOpen;
    const panel = document.getElementById('plx-chat-panel');
    const iconChat = document.querySelector('.plx-icon-chat');
    const iconClose = document.querySelector('.plx-icon-close');
    if (isOpen) {
      panel.classList.replace('plx-hidden', 'plx-visible');
      iconChat.style.display = 'none'; iconClose.style.display = 'block';
      document.getElementById('plx-badge').style.display = 'none';
      setTimeout(() => document.getElementById('plx-input')?.focus(), 280);
    } else {
      panel.classList.replace('plx-visible', 'plx-hidden');
      iconChat.style.display = 'block'; iconClose.style.display = 'none';
    }
  }

  function clearChat() {
    history = [];
    document.getElementById('plx-messages').innerHTML = '';
    addBotMessage('Conversación reiniciada. Indícame el **ID del paciente** (ej. PAC-00123) y tu consulta.', []);
  }

  function toggleQuickPanel() {
    quickPanelOpen = !quickPanelOpen;
    const section = document.getElementById('plx-quick-section');
    const btn = document.getElementById('plx-toggle-quick');
    section.style.display = quickPanelOpen ? 'block' : 'none';
    btn.classList.toggle('plx-toggle-active', quickPanelOpen);
  }

  function renderQuickChips() {
    const c = document.getElementById('plx-quick-chips');
    if (!c) return;
    c.innerHTML = '';
    QUICK_OPTIONS.forEach(({ label, value }) => {
      const chip = document.createElement('button');
      chip.className = 'plx-chip';
      chip.textContent = label;
      chip.addEventListener('click', () => {
        const input = document.getElementById('plx-input');
        input.value = value;
        input.focus();
        // posiciona el cursor al final para que el doctor complete el número
        input.setSelectionRange(input.value.length, input.value.length);
      });
      c.appendChild(chip);
    });
  }

  // ── Enviar ──────────────────────────────────────────────────────────────────
  async function handleSend() {
    if (isLoading) return;
    const input = document.getElementById('plx-input');
    const message = input.value.trim();
    if (!message) return;

    const token = await getToken();
    if (!token) {
      addBotMessage('Tu sesión expiró o no está activa. Vuelve a iniciar sesión para continuar.', []);
      return;
    }

    input.value = '';
    addUserMessage(message);
    history.push({ role: 'user', content: message });

    showTyping();
    isLoading = true; setInputDisabled(true);

    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ message, history }),
      });
      removeTyping();

      if (res.status === 401) { addBotMessage('Tu sesión expiró. Vuelve a iniciar sesión.', []); return; }
      if (res.status === 403) { addBotMessage('Tu cuenta no tiene acceso al expediente clínico.', []); return; }

      const data = await res.json();
      const reply = data.reply || 'Lo siento, no pude obtener una respuesta.';
      addBotMessage(reply, data.sources || []);
      history.push({ role: 'assistant', content: reply });
    } catch {
      removeTyping();
      addBotMessage('Hubo un problema de conexión. Por favor intenta de nuevo.', []);
    } finally {
      isLoading = false; setInputDisabled(false);
    }
  }

  // ── Mensajes ──────────────────────────────────────────────────────────────────
  function addUserMessage(text) {
    const c = document.getElementById('plx-messages');
    const div = document.createElement('div');
    div.className = 'plx-msg plx-msg-user';
    div.innerHTML = `<span class="plx-bubble plx-bubble-user">${escHtml(text)}</span>`;
    c.appendChild(div);
    scrollBottom();
  }

  function docIcon(type) {
    const colors = { pdf: '#e74c3c', txt: '#2980b9', docx: '#2c5fb5', image: '#8e44ad' };
    const col = colors[type] || '#5CB3C1';
    if (type === 'image') {
      return `<svg viewBox="0 0 24 24" fill="none" stroke="${col}" stroke-width="2" width="18">
        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>`;
    }
    return `<svg viewBox="0 0 24 24" fill="none" stroke="${col}" stroke-width="2" width="18">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`;
  }

  function addBotMessage(text, sources) {
    const c = document.getElementById('plx-messages');
    const div = document.createElement('div');
    div.className = 'plx-msg plx-msg-bot';

    const sourcesHtml = sources?.length
      ? `<div class="plx-sources">
           <div class="plx-sources-label">DOCUMENTOS FUENTE</div>
           ${sources.map(s => {
             const type = (s.doc_type || 'txt').toLowerCase();
             const inner = `
               <span class="plx-src-icon">${docIcon(type)}</span>
               <span class="plx-src-text">
                 <span class="plx-src-title">${escHtml(s.filename || 'Documento')}</span>
                 <span class="plx-src-sub">${type.toUpperCase()} · ${s.url ? 'Ver / descargar' : 'No disponible'}</span>
               </span>
               <span class="plx-src-arrow">${s.url ? '›' : ''}</span>`;
             return s.url
               ? `<a href="${escHtml(s.url)}" class="plx-src-btn" target="_blank" rel="noopener">${inner}</a>`
               : `<div class="plx-src-btn plx-src-disabled">${inner}</div>`;
           }).join('')}
         </div>`
      : '';

    div.innerHTML = `
      <div class="plx-bot-row">
        <div class="plx-bot-icon">C</div>
        <div class="plx-bot-content">
          <div class="plx-bubble plx-bubble-bot">${formatText(text)}</div>
          ${sourcesHtml}
        </div>
      </div>`;
    c.appendChild(div);
    scrollBottom();
  }

  function showTyping() {
    const c = document.getElementById('plx-messages');
    const div = document.createElement('div');
    div.id = 'plx-typing';
    div.className = 'plx-msg plx-msg-bot';
    div.innerHTML = `
      <div class="plx-bot-row">
        <div class="plx-bot-icon">C</div>
        <span class="plx-bubble plx-bubble-bot plx-typing"><span></span><span></span><span></span></span>
      </div>`;
    c.appendChild(div);
    scrollBottom();
  }

  function removeTyping() { document.getElementById('plx-typing')?.remove(); }
  function scrollBottom() { const e = document.getElementById('plx-messages'); if (e) e.scrollTop = e.scrollHeight; }
  function setInputDisabled(v) {
    const b = document.getElementById('plx-send-btn');
    const i = document.getElementById('plx-input');
    if (b) b.disabled = v;
    if (i) i.disabled = v;
  }
  function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function formatText(s) {
    return escHtml(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildWidget);
  else buildWidget();
})();
