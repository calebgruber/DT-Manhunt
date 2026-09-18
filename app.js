const steps = ['profile', 'mode', 'matchmaking', 'payment', 'complete'];

const state = {
  user: window.__BOOT_USER__ || null,
  matchmaking: null,
  venmoLink: '',
  dashboard: null,
  pollTimer: null,
  pollBusy: false,
  activeRequests: 0,
  currentStep: null,
  locationWatchId: null,
  locationLastSentAt: 0,
};

const ui = {
  authSection: document.getElementById('authSection'),
  appSection: document.getElementById('appSection'),
  adminLink: document.getElementById('adminLink'),
  loggedInUserLabel: document.getElementById('loggedInUserLabel'),
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
  paymentQrWrap: document.getElementById('paymentQrWrap'),
  paymentQrImage: document.getElementById('paymentQrImage'),
  paymentVenmoLink: document.getElementById('paymentVenmoLink'),
  completePaymentBtn: document.getElementById('completePaymentBtn'),
  switchSoloBtn: document.getElementById('switchSoloBtn'),
  toastContainer: document.getElementById('toastContainer'),
  loadingOverlay: document.getElementById('loadingOverlay'),
  loadingText: document.getElementById('loadingText'),
  userAlertBanner: document.getElementById('userAlertBanner'),
  userAlertText: document.getElementById('userAlertText'),
  acknowledgeAlertBtn: document.getElementById('acknowledgeAlertBtn'),
  dashboardStatusLine: document.getElementById('dashboardStatusLine'),
  dashboardAnnouncement: document.getElementById('dashboardAnnouncement'),
  dashboardMessages: document.getElementById('dashboardMessages'),
  incidentForm: document.getElementById('incidentForm'),
  dashboardIncidents: document.getElementById('dashboardIncidents'),
  killboardSummary: document.getElementById('killboardSummary'),
  killboardCards: document.getElementById('killboardCards'),
  unenrollBtn: document.getElementById('unenrollBtn'),
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
  const labels = ['Profile', 'Mode', 'Match', 'Pay', 'Dashboard'];
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
  if (state.currentStep === step) {
    return;
  }
  [ui.profileStep, ui.modeStep, ui.matchmakingStep, ui.paymentStep, ui.completeStep].forEach((el) => el.classList.add('d-none'));

  let activeStep = ui.completeStep;
  if (step === 'profile') activeStep = ui.profileStep;
  else if (step === 'mode') activeStep = ui.modeStep;
  else if (step === 'matchmaking') activeStep = ui.matchmakingStep;
  else if (step === 'payment') activeStep = ui.paymentStep;

  activeStep.classList.remove('d-none');
  state.currentStep = step;
}

function stopLocationWatch() {
  if (state.locationWatchId !== null && navigator.geolocation) {
    navigator.geolocation.clearWatch(state.locationWatchId);
  }
  state.locationWatchId = null;
}

async function sendLocation(position) {
  const now = Date.now();
  if (now - state.locationLastSentAt < 10000) {
    return;
  }
  state.locationLastSentAt = now;
  try {
    await api(
      'update_location',
      {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
      },
      { loading: false }
    );
  } catch (_) {
    // silent during background location updates
  }
}

function maybeStartLocationWatch() {
  if (!state.user || !state.dashboard) {
    stopLocationWatch();
    return;
  }
  const shouldTrack = state.user.is_enrolled && state.dashboard.game_stage === 'live';
  if (!shouldTrack) {
    stopLocationWatch();
    return;
  }
  if (!navigator.geolocation || state.locationWatchId !== null) {
    return;
  }
  state.locationWatchId = navigator.geolocation.watchPosition(
    sendLocation,
    () => {
      // no-op on geolocation errors
    },
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
  );
}

function renderMessages(messages) {
  ui.dashboardMessages.textContent = '';
  if (!messages.length) {
    const empty = document.createElement('div');
    empty.className = 'text-secondary small';
    empty.textContent = 'No messages yet.';
    ui.dashboardMessages.appendChild(empty);
    return;
  }

  messages.forEach((msg) => {
    const row = document.createElement('div');
    row.className = `p-2 rounded border ${msg.is_read ? 'bg-transparent' : 'bg-azure-lt'}`;

    const top = document.createElement('div');
    top.className = 'd-flex justify-content-between gap-2';
    const scope = document.createElement('small');
    scope.className = 'text-secondary';
    scope.textContent = msg.recipient_scope;
    const time = document.createElement('small');
    time.className = 'text-secondary';
    time.textContent = new Date(msg.created_at).toLocaleString();
    top.append(scope, time);

    const body = document.createElement('div');
    body.className = 'fw-medium';
    body.textContent = msg.body;

    row.append(top, body);

    if (!msg.is_read) {
      const markBtn = document.createElement('button');
      markBtn.className = 'btn btn-sm btn-outline-secondary mt-2';
      markBtn.type = 'button';
      markBtn.textContent = 'Mark read';
      markBtn.addEventListener('click', async () => {
        try {
          await api('mark_message_read', { message_id: msg.message_id }, { loading: false });
          await refreshState(true);
        } catch (error) {
          toast(error.message, 'danger');
        }
      });
      row.appendChild(markBtn);
    }

    ui.dashboardMessages.appendChild(row);
  });
}

function renderIncidents(incidents) {
  ui.dashboardIncidents.textContent = '';
  if (!incidents.length) {
    const empty = document.createElement('div');
    empty.className = 'text-secondary small';
    empty.textContent = 'No incidents submitted.';
    ui.dashboardIncidents.appendChild(empty);
    return;
  }

  incidents.forEach((incident) => {
    const card = document.createElement('div');
    card.className = 'border rounded p-2';
    card.innerHTML = `
      <div class="d-flex justify-content-between gap-2">
        <strong>${incident.incident_type}</strong>
        <span class="badge bg-secondary-lt text-secondary">${incident.severity}</span>
      </div>
      <div class="small text-secondary mb-1">${new Date(incident.created_at).toLocaleString()}</div>
      <div class="mb-1">${incident.details}</div>
      <div class="small">Status: <span class="fw-semibold">${incident.status}</span></div>
    `;
    ui.dashboardIncidents.appendChild(card);
  });
}

function renderKillboard(killboard) {
  const counts = killboard?.counts || { in: 0, eliminated: 0, out: 0 };
  ui.killboardSummary.textContent = `In: ${counts.in} • Eliminated: ${counts.eliminated} • Out: ${counts.out}`;
  ui.killboardCards.textContent = '';

  const players = killboard?.players || [];
  if (!players.length) {
    const empty = document.createElement('div');
    empty.className = 'text-secondary small';
    empty.textContent = 'No players yet.';
    ui.killboardCards.appendChild(empty);
    return;
  }

  players.forEach((player) => {
    const col = document.createElement('div');
    col.className = 'col-12 col-md-6 col-xl-4';
    const badgeClass = player.status === 'in'
      ? 'bg-success-lt text-success'
      : player.status === 'eliminated'
        ? 'bg-warning-lt text-warning'
        : 'bg-secondary-lt text-secondary';

    col.innerHTML = `
      <div class="card card-sm">
        <div class="card-body d-flex justify-content-between align-items-center gap-2">
          <div>
            <div class="fw-semibold">${player.full_name}</div>
            <div class="small text-secondary">${player.mode || 'unset'} mode</div>
          </div>
          <span class="badge ${badgeClass}">${player.status}</span>
        </div>
      </div>
    `;
    ui.killboardCards.appendChild(col);
  });
}

function renderDashboard() {
  const dashboard = state.dashboard;
  if (!dashboard) {
    return;
  }

  const stage = dashboard.game_stage || 'pregame';
  const gameInfo = dashboard.game_info ? ` • ${dashboard.game_info}` : '';
  ui.dashboardStatusLine.textContent = `Stage: ${stage}${gameInfo} • Unread messages: ${dashboard.unread_messages || 0}`;
  ui.dashboardAnnouncement.textContent = dashboard.announcement || 'No announcement yet.';
  renderMessages(dashboard.inbox || []);
  renderIncidents(dashboard.incidents || []);
  renderKillboard(dashboard.killboard || {});
}

function renderUser() {
  const loggedIn = !!state.user;
  ui.authSection.classList.toggle('d-none', loggedIn);
  ui.appSection.classList.toggle('d-none', !loggedIn);
  ui.logoutBtn.classList.toggle('d-none', !loggedIn);
  ui.adminLink.classList.toggle('d-none', !loggedIn || !state.user?.is_admin);
  ui.loggedInUserLabel.classList.toggle('d-none', !loggedIn);

  if (!loggedIn) {
    ui.loggedInUserLabel.textContent = '';
    ui.userAlertBanner.classList.add('d-none');
    stopLocationWatch();
    stopPolling();
    state.currentStep = null;
    return;
  }

  ui.loggedInUserLabel.textContent = `Logged in: ${state.user.full_name}`;

  const alertText = (state.user.pending_alert || '').trim();
  ui.userAlertBanner.classList.toggle('d-none', !alertText);
  ui.userAlertText.textContent = alertText;

  const step = state.user.registration_step || 'profile';
  renderStepper(step);
  showStep(step);

  ui.profileSummary.textContent = `${state.user.full_name} • ${state.user.graduation_year} • ${state.user.concentration}`;

  ui.paymentInfo.textContent = '';
  const modeLine = document.createElement('div');
  modeLine.className = 'small text-muted';
  modeLine.textContent = `Mode: ${state.user.mode || 'Not selected'}`;
  const statusLine = document.createElement('div');
  statusLine.className = 'small mt-1';
  const paymentStatus = state.user.payment_status || 'pending';
  statusLine.textContent = `Payment status: ${paymentStatus}`;
  const mateLine = document.createElement('div');
  mateLine.textContent = state.matchmaking?.teammate ? `Teammate: ${state.matchmaking.teammate.full_name}` : 'Teammate: none';
  ui.paymentInfo.append(modeLine, statusLine, mateLine);

  const venmo = (state.venmoLink || '').trim();
  if (venmo) {
    ui.paymentQrWrap.classList.remove('d-none');
    ui.paymentVenmoLink.href = venmo;
    ui.paymentVenmoLink.textContent = venmo;
    ui.paymentQrImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=${encodeURIComponent(venmo)}`;
  } else {
    ui.paymentQrWrap.classList.add('d-none');
  }

  if (paymentStatus === 'approved') {
    ui.completePaymentBtn.disabled = true;
    ui.completePaymentBtn.textContent = 'Payment Approved';
  } else if (paymentStatus === 'submitted') {
    ui.completePaymentBtn.disabled = true;
    ui.completePaymentBtn.textContent = 'Payment Submitted (Awaiting Approval)';
  } else {
    ui.completePaymentBtn.disabled = false;
    ui.completePaymentBtn.textContent = 'Submit Payment for Approval';
  }

  renderDashboard();
  maybeStartLocationWatch();
  startPolling();
}

async function refreshState(quiet = false) {
  if (!state.user) return;
  const data = await api('state', {}, { loading: !quiet, loadingText: 'Syncing account...' });
  state.user = data.user;
  state.matchmaking = { teammate: data.teammate };
  state.venmoLink = data.venmo_link || '';
  state.dashboard = data.dashboard || null;
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
    state.venmoLink = data.venmo_link || '';
    state.dashboard = data.dashboard || null;
    renderUser();
  } catch (_) {
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
      state.dashboard = null;
      stopLocationWatch();
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
      toast('Payment submitted for admin approval', 'success');
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

  ui.acknowledgeAlertBtn?.addEventListener('click', async () => {
    try {
      await api('acknowledge_alert', {}, { loading: false });
      await refreshState(true);
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.incidentForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.incidentForm).entries());
      await api('report_incident', payload, { loadingText: 'Submitting incident...' });
      ui.incidentForm.reset();
      toast('Incident report sent to admins.', 'success');
      await refreshState(true);
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.unenrollBtn?.addEventListener('click', async () => {
    const confirmed = window.confirm('Are you sure you want to unenroll? This will remove you from active gameplay.');
    if (!confirmed) return;
    try {
      const data = await api('unenroll', {}, { loadingText: 'Unenrolling...' });
      state.user = data.user;
      await refreshState();
      toast('You have been unenrolled.', 'warning');
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
