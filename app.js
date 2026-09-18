const steps = ['profile', 'mode', 'matchmaking', 'payment', 'complete'];

const state = {
  user: window.__BOOT_USER__ || null,
  matchmaking: null,
  pollTimer: null,
  pollBusy: false,
  activeRequests: 0,
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
  switchSoloBtn: document.getElementById('switchSoloBtn'),
  toastContainer: document.getElementById('toastContainer'),
  loadingOverlay: document.getElementById('loadingOverlay'),
  loadingText: document.getElementById('loadingText'),
};

function setLoading(show, text = 'Loading...') {
  if (text) {
    ui.loadingText.textContent = text;
  }
  ui.loadingOverlay.classList.toggle('is-visible', show);
}

function beginLoading(text) {
  state.activeRequests += 1;
  setLoading(true, text);
}

function endLoading() {
  state.activeRequests = Math.max(0, state.activeRequests - 1);
  if (state.activeRequests === 0) {
    setLoading(false);
  }
}

function toast(message, type = 'primary') {
  const wrapper = document.createElement('div');
  wrapper.className = `toast align-items-center text-bg-${type} border-0`;
  wrapper.setAttribute('role', 'status');
  wrapper.setAttribute('aria-live', 'polite');
  wrapper.setAttribute('aria-atomic', 'true');
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
  if (window.bootstrap?.Toast) {
    const t = new bootstrap.Toast(wrapper, { delay: 3000 });
    t.show();
    wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
    return;
  }
  wrapper.classList.add('show');
  setTimeout(() => wrapper.remove(), 3000);
}

async function api(action, payload = {}, options = {}) {
  const { loading = true, loadingText = 'Loading...' } = options;
  if (loading) {
    beginLoading(loadingText);
  }
  try {
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
  } finally {
    if (loading) {
      endLoading();
    }
  }
}

function setAuthTab(tab) {
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    const active = btn.dataset.authTab === tab;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
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
  [ui.profileStep, ui.modeStep, ui.matchmakingStep, ui.paymentStep, ui.completeStep].forEach((el) => el.classList.remove('step-animate'));

  let activeStep = ui.completeStep;
  if (step === 'profile') activeStep = ui.profileStep;
  else if (step === 'mode') activeStep = ui.modeStep;
  else if (step === 'matchmaking') activeStep = ui.matchmakingStep;
  else if (step === 'payment') activeStep = ui.paymentStep;

  activeStep.classList.remove('d-none');
  requestAnimationFrame(() => activeStep.classList.add('step-animate'));
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

  ui.profileSummary.textContent = `${state.user.full_name} • ${state.user.graduation_year} • ${state.user.concentration}`;

  ui.paymentInfo.textContent = '';
  const modeLine = document.createElement('div');
  modeLine.className = 'small text-muted';
  modeLine.textContent = `Mode: ${state.user.mode || 'Not selected'}`;
  const mateLine = document.createElement('div');
  mateLine.textContent = state.matchmaking?.teammate ? `Teammate: ${state.matchmaking.teammate.full_name}` : 'Teammate: none';
  ui.paymentInfo.append(modeLine, mateLine);

  startPolling();
}

async function refreshState(quiet = false) {
  if (!state.user) return;
  const data = await api('state', {}, { loading: !quiet, loadingText: 'Syncing account...' });
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
    const data = await api('matchmaking_state', {}, { loading: false });
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
    if (!state.pollBusy) {
      toast(error.message, 'danger');
    }
  }
}

async function pollTick() {
  if (!state.user || state.pollBusy) return;
  state.pollBusy = true;
  try {
    if (state.user.registration_step === 'matchmaking') {
      await loadMatchmakingState();
      return;
    }
    const data = await api('state', {}, { loading: false });
    state.user = data.user;
    state.matchmaking = { teammate: data.teammate };
    renderUser();
  } catch (error) {
    // keep quiet during background polling
  } finally {
    state.pollBusy = false;
  }
}

function startPolling() {
  if (state.pollTimer) return;
  pollTick();
  state.pollTimer = setInterval(pollTick, 1200);
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
    try {
      await api('logout', {}, { loadingText: 'Logging out...' });
      state.user = null;
      state.matchmaking = null;
      renderUser();
      toast('Logged out', 'secondary');
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.continueToMode.addEventListener('click', async () => {
    try {
      const data = await api('set_step', { step: 'mode' }, { loadingText: 'Loading next step...' });
      state.user = data.user;
      await refreshState();
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  document.querySelectorAll('.mode-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const data = await api('set_mode', { mode: btn.dataset.mode }, { loadingText: 'Updating mode...' });
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
      const data = await api('search_users', { query: ui.searchInput.value.trim() }, { loading: false });
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
      const data = await api('complete_payment', {}, { loadingText: 'Finalizing payment...' });
      state.user = data.user;
      await refreshState();
      toast('Payment saved', 'success');
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.switchSoloBtn.addEventListener('click', async () => {
    try {
      const data = await api('switch_to_solo', {}, { loadingText: 'Switching to solo...' });
      state.user = data.user;
      state.matchmaking = null;
      await refreshState();
      toast('Switched to solo. Continue to payment.', 'info');
    } catch (error) {
      toast(error.message, 'danger');
    }
  });
}

async function init() {
  setLoading(true, 'Preparing app...');
  wireEvents();
  setAuthTab('login');
  if (state.user) {
    await refreshState(true);
  } else {
    renderUser();
  }
  setLoading(false);
}

init();
