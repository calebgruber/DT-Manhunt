const steps = ['profile', 'mode', 'matchmaking', 'payment', 'complete'];

const state = {
  user: window.__BOOT_USER__ || null,
  matchmaking: null,
  pollTimer: null,
};

const ui = {
  authSection: document.getElementById('authSection'),
  appSection: document.getElementById('appSection'),
  logoutBtn: document.getElementById('logoutBtn'),
  loginPanel: document.getElementById('loginPanel'),
  registerPanel: document.getElementById('registerPanel'),
  loginForm: document.getElementById('loginForm'),
  registerForm: document.getElementById('registerForm'),
  stepper: document.getElementById('stepper'),
  resumeCard: document.getElementById('resumeCard'),
  profileStep: document.getElementById('profileStep'),
  modeStep: document.getElementById('modeStep'),
  matchmakingStep: document.getElementById('matchmakingStep'),
  paymentStep: document.getElementById('paymentStep'),
  completeStep: document.getElementById('completeStep'),
  profileSummary: document.getElementById('profileSummary'),
  continueToMode: document.getElementById('continueToMode'),
  searchInput: document.getElementById('searchInput'),
  searchBtn: document.getElementById('searchBtn'),
  searchResults: document.getElementById('searchResults'),
  outgoingInvite: document.getElementById('outgoingInvite'),
  incomingInvites: document.getElementById('incomingInvites'),
  teammateCard: document.getElementById('teammateCard'),
  paymentInfo: document.getElementById('paymentInfo'),
  completePaymentBtn: document.getElementById('completePaymentBtn'),
  toastContainer: document.getElementById('toastContainer'),
};

function toast(message, type = 'primary') {
  const wrapper = document.createElement('div');
  wrapper.className = `toast align-items-center text-bg-${type} border-0`;
  const row = document.createElement('div');
  row.className = 'd-flex';
  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = String(message);
  const close = document.createElement('button');
  close.className = 'btn-close btn-close-white me-2 m-auto';
  close.type = 'button';
  close.setAttribute('data-bs-dismiss', 'toast');
  row.append(body, close);
  wrapper.appendChild(row);
  ui.toastContainer.appendChild(wrapper);
  const t = new bootstrap.Toast(wrapper, { delay: 3000 });
  t.show();
  wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
}

async function api(action, payload = {}) {
  const res = await fetch('/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...payload }),
  });
  const data = await res.json();
  if (!data.ok) {
    throw new Error(data.message || 'Request failed');
  }
  return data;
}

function setAuthTab(tab) {
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    const active = btn.dataset.authTab === tab;
    btn.classList.toggle('active', active);
  });
  ui.loginPanel.classList.toggle('d-none', tab !== 'login');
  ui.registerPanel.classList.toggle('d-none', tab !== 'register');
}

function renderStepper(step) {
  const idx = Math.max(0, steps.indexOf(step));
  ui.stepper.innerHTML = '';
  const labels = ['Profile', 'Mode', 'Match', 'Pay', 'Done'];
  labels.forEach((label, i) => {
    const node = document.createElement('div');
    node.className = `step ${i <= idx ? 'active' : ''}`;
    const badge = document.createElement('span');
    badge.textContent = String(i + 1);
    const text = document.createElement('small');
    text.textContent = label;
    node.append(badge, text);
    ui.stepper.appendChild(node);
  });
}

function showStep(step) {
  [ui.profileStep, ui.modeStep, ui.matchmakingStep, ui.paymentStep, ui.completeStep].forEach((el) => el.classList.add('d-none'));
  stopPolling();

  if (step === 'profile') ui.profileStep.classList.remove('d-none');
  else if (step === 'mode') ui.modeStep.classList.remove('d-none');
  else if (step === 'matchmaking') {
    ui.matchmakingStep.classList.remove('d-none');
    startPolling();
  } else if (step === 'payment') ui.paymentStep.classList.remove('d-none');
  else ui.completeStep.classList.remove('d-none');
}

function renderUser() {
  const loggedIn = !!state.user;
  ui.authSection.classList.toggle('d-none', loggedIn);
  ui.appSection.classList.toggle('d-none', !loggedIn);
  ui.logoutBtn.classList.toggle('d-none', !loggedIn);

  if (!loggedIn) {
    stopPolling();
    return;
  }

  const step = state.user.registration_step || 'profile';
  renderStepper(step);
  showStep(step);

  ui.resumeCard.classList.remove('d-none');
  ui.resumeCard.textContent = `Resumed at step: ${step}`;

  ui.profileSummary.textContent = `${state.user.full_name} • ${state.user.graduation_year} • ${state.user.concentration}`;

  ui.paymentInfo.textContent = '';
  const modeLine = document.createElement('div');
  modeLine.className = 'small text-muted';
  modeLine.textContent = `Mode: ${state.user.mode || 'Not selected'}`;
  const mateLine = document.createElement('div');
  mateLine.textContent = state.matchmaking?.teammate ? `Teammate: ${state.matchmaking.teammate.full_name}` : 'Teammate: none';
  ui.paymentInfo.append(modeLine, mateLine);
}

async function refreshState() {
  if (!state.user) return;
  const data = await api('state');
  state.user = data.user;
  state.matchmaking = { teammate: data.teammate };
  renderUser();
}

function renderSearchResults(results) {
  ui.searchResults.textContent = '';
  if (!results.length) {
    const empty = document.createElement('div');
    empty.className = 'small text-muted';
    empty.textContent = 'No results found.';
    ui.searchResults.appendChild(empty);
    return;
  }

  results.forEach((row) => {
    const item = document.createElement('div');
    item.className = 'list-group-item list-group-item-action';

    const wrap = document.createElement('div');
    wrap.className = 'd-flex justify-content-between align-items-center gap-2';

    const info = document.createElement('div');
    const name = document.createElement('div');
    name.className = 'fw-semibold';
    name.textContent = row.full_name;
    const meta = document.createElement('small');
    meta.className = 'text-muted';
    meta.textContent = `${row.graduation_year} • ${row.concentration}`;

    const inviteBtn = document.createElement('button');
    inviteBtn.className = 'btn btn-sm btn-primary';
    inviteBtn.type = 'button';
    inviteBtn.textContent = 'Invite';
    inviteBtn.addEventListener('click', async () => {
      try {
        await api('send_invite', { invitee_user_id: row.id });
        toast(`Invite sent to ${row.full_name}`, 'success');
        await loadMatchmakingState();
      } catch (error) {
        toast(error.message, 'danger');
      }
    });

    info.append(name, meta);
    wrap.append(info, inviteBtn);
    item.appendChild(wrap);
    ui.searchResults.appendChild(item);
  });
}

function renderIncoming(incoming) {
  ui.incomingInvites.textContent = '';
  if (!incoming.length) {
    const empty = document.createElement('div');
    empty.className = 'small text-muted';
    empty.textContent = 'No incoming invites.';
    ui.incomingInvites.appendChild(empty);
    return;
  }

  incoming.forEach((invite) => {
    const card = document.createElement('div');
    card.className = 'invite-card';

    const text = document.createElement('div');
    text.className = 'fw-semibold mb-2';
    text.textContent = `${invite.inviter_full_name} invited you`;

    const actions = document.createElement('div');
    actions.className = 'd-flex gap-2';

    const accept = document.createElement('button');
    accept.className = 'btn btn-success btn-sm';
    accept.type = 'button';
    accept.textContent = 'Accept';
    accept.addEventListener('click', () => replyInvite(invite.id, 'accept'));

    const decline = document.createElement('button');
    decline.className = 'btn btn-outline-light btn-sm';
    decline.type = 'button';
    decline.textContent = 'Decline';
    decline.addEventListener('click', () => replyInvite(invite.id, 'decline'));

    actions.append(accept, decline);
    card.append(text, actions);
    ui.incomingInvites.appendChild(card);
  });
}

function renderOutgoing(outgoing) {
  if (!outgoing) {
    ui.outgoingInvite.textContent = 'No outgoing invite.';
    return;
  }

  let text = `Invite to ${outgoing.invitee_full_name}: ${outgoing.status}`;
  if (outgoing.status === 'declined') text += ' — choose a new teammate.';
  if (outgoing.status === 'accepted') text += ' — moving to payment.';
  ui.outgoingInvite.textContent = text;
}

async function replyInvite(inviteId, decision) {
  try {
    await api('respond_invite', { invite_id: inviteId, decision });
    toast(decision === 'accept' ? 'Invite accepted' : 'Invite declined', 'success');
    await loadMatchmakingState();
  } catch (error) {
    toast(error.message, 'danger');
  }
}

async function loadMatchmakingState() {
  if (!state.user || state.user.registration_step !== 'matchmaking') return;
  try {
    const data = await api('matchmaking_state');
    state.user = data.user;
    state.matchmaking = {
      incoming: data.incoming,
      outgoing: data.outgoing,
      teammate: data.teammate,
    };

    renderIncoming(data.incoming || []);
    renderOutgoing(data.outgoing || null);

    if (state.matchmaking.teammate) {
      ui.teammateCard.classList.remove('d-none');
      ui.teammateCard.textContent = `Matched with ${state.matchmaking.teammate.full_name}. Continue to payment.`;
    } else {
      ui.teammateCard.classList.add('d-none');
    }

    if (data.user.registration_step !== 'matchmaking') {
      await refreshState();
    } else {
      renderUser();
    }
  } catch (error) {
    toast(error.message, 'danger');
  }
}

function startPolling() {
  if (state.pollTimer) return;
  loadMatchmakingState();
  state.pollTimer = setInterval(loadMatchmakingState, 3000);
}

function stopPolling() {
  if (!state.pollTimer) return;
  clearInterval(state.pollTimer);
  state.pollTimer = null;
}

function wireEvents() {
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    btn.addEventListener('click', () => setAuthTab(btn.dataset.authTab));
  });

  ui.loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.loginForm).entries());
      const data = await api('login', payload);
      state.user = data.user;
      toast('Logged in', 'success');
      await refreshState();
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.registerForm).entries());
      const data = await api('register', payload);
      state.user = data.user;
      toast('Account created', 'success');
      await refreshState();
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.logoutBtn.addEventListener('click', async () => {
    await api('logout');
    state.user = null;
    state.matchmaking = null;
    renderUser();
    toast('Logged out', 'secondary');
  });

  ui.continueToMode.addEventListener('click', async () => {
    try {
      const data = await api('set_step', { step: 'mode' });
      state.user = data.user;
      await refreshState();
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  document.querySelectorAll('.mode-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const data = await api('set_mode', { mode: btn.dataset.mode });
        state.user = data.user;
        state.matchmaking = null;
        await refreshState();
      } catch (error) {
        toast(error.message, 'danger');
      }
    });
  });

  ui.searchBtn.addEventListener('click', async () => {
    try {
      const data = await api('search_users', { query: ui.searchInput.value.trim() });
      renderSearchResults(data.results || []);
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      ui.searchBtn.click();
    }
  });

  ui.completePaymentBtn.addEventListener('click', async () => {
    try {
      const data = await api('complete_payment');
      state.user = data.user;
      await refreshState();
      toast('Payment saved', 'success');
    } catch (error) {
      toast(error.message, 'danger');
    }
  });
}

async function init() {
  wireEvents();
  setAuthTab('login');
  if (state.user) {
    await refreshState();
  } else {
    renderUser();
  }
}

init();
