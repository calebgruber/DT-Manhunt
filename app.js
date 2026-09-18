const state = {
  user: window.__BOOT_USER__ || null,
  authTab: 'login',
  polling: null,
  matchmaking: null,
};

const steps = ['profile', 'mode', 'matchmaking', 'payment', 'complete'];

const el = {
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

function showToast(message, tone = 'primary') {
  const wrapper = document.createElement('div');
  wrapper.className = `toast align-items-center text-bg-${tone} border-0`;
  wrapper.role = 'alert';
  const layout = document.createElement('div');
  layout.className = 'd-flex';
  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = String(message ?? '');
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn-close btn-close-white me-2 m-auto';
  close.setAttribute('data-bs-dismiss', 'toast');
  layout.appendChild(body);
  layout.appendChild(close);
  wrapper.appendChild(layout);
  el.toastContainer.appendChild(wrapper);
  const toast = new bootstrap.Toast(wrapper, { delay: 2500 });
  toast.show();
  wrapper.addEventListener('hidden.bs.toast', () => wrapper.remove());
}

async function api(action, payload = {}) {
  const res = await fetch('/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...payload }),
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.message || 'Request failed');
  return data;
}

function setAuthTab(tab) {
  state.authTab = tab;
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.authTab === tab);
  });
  el.loginPanel.classList.toggle('d-none', tab !== 'login');
  el.registerPanel.classList.toggle('d-none', tab !== 'register');
}

function stepIndex(step) {
  const idx = steps.indexOf(step);
  return idx === -1 ? 0 : idx;
}

function renderStepper(step) {
  const current = stepIndex(step);
  const labels = [
    { key: 'profile', label: 'Profile' },
    { key: 'mode', label: 'Mode' },
    { key: 'matchmaking', label: 'Match' },
    { key: 'payment', label: 'Pay' },
    { key: 'complete', label: 'Done' },
  ];

  el.stepper.innerHTML = labels
    .map((s, idx) => `<div class="step ${idx <= current ? 'active' : ''}"><span>${idx + 1}</span><small>${s.label}</small></div>`)
    .join('');
}

function showOnlyStep(step) {
  [el.profileStep, el.modeStep, el.matchmakingStep, el.paymentStep, el.completeStep].forEach((node) => node.classList.add('d-none'));

  if (step === 'profile') {
    el.profileStep.classList.remove('d-none');
  } else if (step === 'mode') {
    el.modeStep.classList.remove('d-none');
  } else if (step === 'matchmaking') {
    el.matchmakingStep.classList.remove('d-none');
    startMatchmakingPolling();
  } else if (step === 'payment') {
    el.paymentStep.classList.remove('d-none');
    stopMatchmakingPolling();
  } else {
    el.completeStep.classList.remove('d-none');
    stopMatchmakingPolling();
  }
}

function setLoggedIn(isLoggedIn) {
  el.authSection.classList.toggle('d-none', isLoggedIn);
  el.appSection.classList.toggle('d-none', !isLoggedIn);
  el.logoutBtn.classList.toggle('d-none', !isLoggedIn);
  if (!isLoggedIn) stopMatchmakingPolling();
}

function renderUser() {
  if (!state.user) {
    setLoggedIn(false);
    return;
  }

  setLoggedIn(true);
  const step = state.user.registration_step || 'mode';
  renderStepper(step);
  showOnlyStep(step);

  el.resumeCard.classList.remove('d-none');
  el.resumeCard.textContent = `Resumed at step: ${step.charAt(0).toUpperCase() + step.slice(1)}`;

  el.profileSummary.textContent = `${state.user.full_name} (@${state.user.display_name}) • ${state.user.graduation_year} • ${state.user.concentration}`;

  const teammateText = state.matchmaking?.teammate
    ? `Teammate matched: ${state.matchmaking.teammate.display_name}`
    : 'No teammate matched yet';

  el.paymentInfo.textContent = '';
  const modeLine = document.createElement('div');
  modeLine.className = 'small text-secondary';
  modeLine.textContent = `Mode: ${state.user.mode || 'Not set'}`;
  const teammateLine = document.createElement('div');
  teammateLine.textContent = teammateText;
  el.paymentInfo.appendChild(modeLine);
  el.paymentInfo.appendChild(teammateLine);

  if (state.user.teammate_user_id && state.matchmaking?.teammate) {
    el.teammateCard.classList.remove('d-none');
    el.teammateCard.textContent = `Matched with ${state.matchmaking.teammate.display_name}. Both users move to payment.`;
  } else {
    el.teammateCard.classList.add('d-none');
  }
}

async function refreshState() {
  if (!state.user) return;
  const data = await api('state');
  state.user = data.user;
  state.matchmaking = { teammate: data.teammate };
  renderUser();
}

async function submitLogin(evt) {
  evt.preventDefault();
  const formData = new FormData(el.loginForm);
  try {
    const data = await api('login', Object.fromEntries(formData.entries()));
    state.user = data.user;
    showToast('Logged in', 'success');
    await refreshState();
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

async function submitRegister(evt) {
  evt.preventDefault();
  const formData = new FormData(el.registerForm);
  try {
    const data = await api('register', Object.fromEntries(formData.entries()));
    state.user = data.user;
    showToast('Account created', 'success');
    await refreshState();
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

async function logout() {
  await api('logout');
  state.user = null;
  state.matchmaking = null;
  renderUser();
}

async function setMode(mode) {
  try {
    const data = await api('set_mode', { mode });
    state.user = data.user;
    state.matchmaking = null;
    showToast(`${mode.toUpperCase()} selected`, 'success');
    await refreshState();
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

function renderSearchResults(results) {
  el.searchResults.innerHTML = '';
  if (!results.length) {
    el.searchResults.innerHTML = '<div class="text-secondary small">No users found.</div>';
    return;
  }

  results.forEach((user) => {
    const row = document.createElement('div');
    row.className = 'list-group-item list-group-item-action bg-dark text-light border-secondary';

    const layout = document.createElement('div');
    layout.className = 'd-flex justify-content-between align-items-center gap-2';
    const info = document.createElement('div');
    const name = document.createElement('div');
    name.className = 'fw-semibold';
    name.textContent = user.display_name;
    const meta = document.createElement('small');
    meta.className = 'text-secondary';
    meta.textContent = `${user.graduation_year} • ${user.concentration}`;
    const button = document.createElement('button');
    button.className = 'btn btn-sm btn-warning';
    button.textContent = 'Invite';

    info.appendChild(name);
    info.appendChild(meta);
    layout.appendChild(info);
    layout.appendChild(button);
    row.appendChild(layout);

    button.addEventListener('click', async () => {
      try {
        await api('send_invite', { invitee_user_id: user.id });
        showToast(`Invite sent to ${user.display_name}`, 'success');
        await fetchMatchmakingState();
      } catch (error) {
        showToast(error.message, 'danger');
      }
    });
    el.searchResults.appendChild(row);
  });
}

async function searchUsers() {
  try {
    const data = await api('search_users', { query: el.searchInput.value.trim() });
    renderSearchResults(data.results || []);
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

function renderIncomingInvites(incoming = []) {
  el.incomingInvites.innerHTML = '';
  if (!incoming.length) {
    el.incomingInvites.innerHTML = '<div class="text-secondary small">No incoming invites.</div>';
    return;
  }

  incoming.forEach((invite) => {
    const card = document.createElement('div');
    card.className = 'p-2 border border-secondary rounded';
    const title = document.createElement('div');
    title.className = 'fw-semibold mb-2';
    title.textContent = `${invite.inviter_display_name} invited you`;

    const buttonRow = document.createElement('div');
    buttonRow.className = 'd-flex gap-2';

    const acceptBtn = document.createElement('button');
    acceptBtn.className = 'btn btn-success btn-sm';
    acceptBtn.dataset.decision = 'accept';
    acceptBtn.textContent = 'Accept';

    const declineBtn = document.createElement('button');
    declineBtn.className = 'btn btn-outline-danger btn-sm';
    declineBtn.dataset.decision = 'decline';
    declineBtn.textContent = 'Decline';

    [acceptBtn, declineBtn].forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api('respond_invite', { invite_id: invite.id, decision: btn.dataset.decision });
          showToast(btn.dataset.decision === 'accept' ? 'Invite accepted' : 'Invite declined', 'success');
          await fetchMatchmakingState();
        } catch (error) {
          showToast(error.message, 'danger');
        }
      });
    });

    buttonRow.appendChild(acceptBtn);
    buttonRow.appendChild(declineBtn);
    card.appendChild(title);
    card.appendChild(buttonRow);

    el.incomingInvites.appendChild(card);
  });
}

function renderOutgoingInvite(outgoing) {
  if (!outgoing) {
    el.outgoingInvite.textContent = 'No outgoing invite.';
    return;
  }

  let text = `Invite to ${outgoing.invitee_display_name}: ${outgoing.status}`;
  if (outgoing.status === 'declined') {
    text += ' — select a new teammate.';
  }
  if (outgoing.status === 'accepted') {
    text += ' — moving to payment.';
  }

  el.outgoingInvite.textContent = text;
}

async function fetchMatchmakingState() {
  if (!state.user || state.user.registration_step !== 'matchmaking') return;

  try {
    const data = await api('matchmaking_state');
    state.matchmaking = {
      incoming: data.incoming,
      outgoing: data.outgoing,
      teammate: data.teammate,
    };

    state.user = data.user;
    renderIncomingInvites(data.incoming || []);
    renderOutgoingInvite(data.outgoing || null);

    if (data.user.registration_step !== 'matchmaking') {
      showToast('Match completed. Proceeding to payment.', 'success');
      await refreshState();
      return;
    }

    renderUser();
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

function startMatchmakingPolling() {
  if (state.polling) return;
  fetchMatchmakingState();
  state.polling = setInterval(fetchMatchmakingState, 3000);
}

function stopMatchmakingPolling() {
  if (!state.polling) return;
  clearInterval(state.polling);
  state.polling = null;
}

async function completePayment() {
  try {
    const data = await api('complete_payment');
    state.user = data.user;
    showToast('Payment marked complete', 'success');
    await refreshState();
  } catch (error) {
    showToast(error.message, 'danger');
  }
}

function wireEvents() {
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => {
    btn.addEventListener('click', () => setAuthTab(btn.dataset.authTab));
  });

  el.loginForm.addEventListener('submit', submitLogin);
  el.registerForm.addEventListener('submit', submitRegister);
  el.logoutBtn.addEventListener('click', logout);

  document.querySelectorAll('.mode-btn').forEach((btn) => {
    btn.addEventListener('click', () => setMode(btn.dataset.mode));
  });

  el.searchBtn.addEventListener('click', searchUsers);
  el.searchInput.addEventListener('keypress', (evt) => {
    if (evt.key === 'Enter') {
      evt.preventDefault();
      searchUsers();
    }
  });

  el.continueToMode.addEventListener('click', async () => {
    try {
      const data = await api('set_step', { step: 'mode' });
      state.user = data.user;
      await refreshState();
    } catch (error) {
      showToast(error.message, 'danger');
    }
  });

  el.completePaymentBtn.addEventListener('click', completePayment);
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
