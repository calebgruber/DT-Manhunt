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
  userMap: null,
  userMapMarkers: { self: null, mate: null },
  liveFullscreenAttempted: false,
  clockTickTimer: null,
  clockRenderSeconds: null,
  clockDirection: 'down',
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
  dashboardRoleLine: document.getElementById('dashboardRoleLine'),
  openIncidentModalBtn: document.getElementById('openIncidentModalBtn'),
  incidentForm: document.getElementById('incidentForm'),
  withdrawBtn: document.getElementById('withdrawBtn'),
  statInCount: document.getElementById('statInCount'),
  statSeekerCount: document.getElementById('statSeekerCount'),
  statEliminatedCount: document.getElementById('statEliminatedCount'),
  gameClockValue: document.getElementById('gameClockValue'),
  persistentMessageAlerts: document.getElementById('persistentMessageAlerts'),
  duoInfoPanel: document.getElementById('duoInfoPanel'),
  userMap: document.getElementById('userMap'),
  userMapFullscreenBtn: document.getElementById('userMapFullscreenBtn'),
  userKillboardFullscreenBtn: document.getElementById('userKillboardFullscreenBtn'),
  killboardSummary: document.getElementById('killboardSummary'),
  killboardCards: document.getElementById('killboardCards'),
  userGameShell: document.getElementById('userGameShell'),
  incidentModal: document.getElementById('incidentModal'),
};

function setLoading(show, text = 'Loading...') {
  if (text) ui.loadingText.textContent = text;
  ui.loadingOverlay.classList.toggle('is-visible', show);
}

function beginLoading(text) {
  state.activeRequests += 1;
  setLoading(true, text);
}

function endLoading() {
  state.activeRequests = Math.max(0, state.activeRequests - 1);
  if (state.activeRequests === 0) setLoading(false);
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
  }
}

async function api(action, payload = {}, options = {}) {
  const { loading = true, loadingText = 'Loading...' } = options;
  if (loading) beginLoading(loadingText);
  try {
    const res = await fetch('/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || 'Request failed');
    return data;
  } finally {
    if (loading) endLoading();
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
    node.innerHTML = `<span>${i + 1}</span><small>${label}</small>`;
    ui.stepper.appendChild(node);
  });
}

function showStep(step) {
  if (state.currentStep === step) return;
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
  if (state.locationWatchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(state.locationWatchId);
  state.locationWatchId = null;
}

async function sendLocation(position) {
  const now = Date.now();
  if (now - state.locationLastSentAt < 60000) return;
  state.locationLastSentAt = now;
  try {
    await api('update_location', {
      latitude: position.coords.latitude,
      longitude: position.coords.longitude,
      accuracy: position.coords.accuracy,
    }, { loading: false });
  } catch (_) {
    // silent
  }
}

function maybeStartLocationWatch() {
  if (!state.user || !state.dashboard) return stopLocationWatch();
  const shouldTrack = state.dashboard.game_stage === 'live' && state.user.game_status !== 'withdrawn';
  if (!shouldTrack || !navigator.geolocation) return stopLocationWatch();
  if (state.locationWatchId !== null) return;
  state.locationWatchId = navigator.geolocation.watchPosition(sendLocation, () => {}, {
    enableHighAccuracy: true,
    maximumAge: 60000,
    timeout: 15000,
  });
}

function formatClock(seconds) {
  const safe = Math.max(0, Math.floor(seconds));
  const h = Math.floor(safe / 3600);
  const m = Math.floor((safe % 3600) / 60);
  const s = safe % 60;
  if (h > 0) return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function stopClockTick() {
  if (!state.clockTickTimer) return;
  clearInterval(state.clockTickTimer);
  state.clockTickTimer = null;
}

function startClockTick() {
  stopClockTick();
  if (state.clockRenderSeconds === null) return;
  state.clockTickTimer = setInterval(() => {
    if (state.clockDirection === 'down') {
      state.clockRenderSeconds = Math.max(0, state.clockRenderSeconds - 1);
    } else {
      state.clockRenderSeconds += 1;
    }
    ui.gameClockValue.textContent = formatClock(state.clockRenderSeconds);
  }, 1000);
}

function ensureUserMap() {
  if (!ui.userMap || !window.L || state.userMap) return;
  state.userMap = L.map('userMap').setView([41.04, -73.7], 14);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap &copy; CARTO',
  }).addTo(state.userMap);
}

function updateUserMap(dashboard) {
  ensureUserMap();
  if (!state.userMap) return;
  const points = [];

  const selfLoc = dashboard?.duo?.self_location;
  if (selfLoc?.latitude != null && selfLoc?.longitude != null) {
    points.push([selfLoc.latitude, selfLoc.longitude]);
    if (!state.userMapMarkers.self) {
      state.userMapMarkers.self = L.marker([selfLoc.latitude, selfLoc.longitude]).addTo(state.userMap).bindPopup('You');
    } else {
      state.userMapMarkers.self.setLatLng([selfLoc.latitude, selfLoc.longitude]);
    }
  }

  const mate = dashboard?.duo?.teammate;
  const mateLoc = dashboard?.duo?.teammate_location;
  if (mate && mateLoc?.latitude != null && mateLoc?.longitude != null) {
    points.push([mateLoc.latitude, mateLoc.longitude]);
    if (!state.userMapMarkers.mate) {
      state.userMapMarkers.mate = L.marker([mateLoc.latitude, mateLoc.longitude]).addTo(state.userMap).bindPopup(mate.full_name);
    } else {
      state.userMapMarkers.mate.setLatLng([mateLoc.latitude, mateLoc.longitude]);
      state.userMapMarkers.mate.bindPopup(mate.full_name);
    }
  }

  if (points.length) {
    const bounds = L.latLngBounds(points);
    state.userMap.fitBounds(bounds.pad(0.3));
  }
}

function renderPersistentAlerts(messages) {
  ui.persistentMessageAlerts.textContent = '';
  if (!messages.length) {
    ui.persistentMessageAlerts.innerHTML = '<div class="alert alert-secondary mb-0">No active admin alerts.</div>';
    return;
  }

  messages.forEach((msg) => {
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger mb-0 persistent-alert';
    alert.innerHTML = `<div class="fw-semibold mb-1">${msg.recipient_scope}</div><div>${msg.body}</div><small class="text-white-50">${new Date(msg.created_at).toLocaleString()}</small>`;
    ui.persistentMessageAlerts.appendChild(alert);
  });
}

function renderKillboard(killboard) {
  const counts = killboard?.counts || { in: 0, seeker: 0, eliminated: 0, withdrawn: 0 };
  ui.killboardSummary.textContent = `In: ${counts.in} • Seekers: ${counts.seeker} • Eliminated: ${counts.eliminated} • Withdrawn: ${counts.withdrawn}`;
  ui.killboardCards.textContent = '';

  const players = killboard?.players || [];
  if (!players.length) {
    ui.killboardCards.innerHTML = '<div class="text-secondary small">No players yet.</div>';
    return;
  }

  players.forEach((player) => {
    const card = document.createElement('div');
    const statusClass = player.status === 'in'
      ? 'kb-in'
      : player.status === 'eliminated'
        ? 'kb-eliminated'
        : player.status === 'seeker'
          ? 'kb-seeker'
          : 'kb-withdrawn';
    card.className = `kb-card ${statusClass}`;
    card.innerHTML = `<div class="fw-semibold">${player.full_name}</div><div class="small">${player.status}</div>`;
    ui.killboardCards.appendChild(card);
  });
}

function renderDuoInfo(dashboard) {
  const mate = dashboard?.duo?.teammate;
  const selfLoc = dashboard?.duo?.self_location;
  const mateLoc = dashboard?.duo?.teammate_location;
  const lines = [];
  lines.push(`<div><strong>You:</strong> ${state.user?.full_name || 'Unknown'}</div>`);
  lines.push(`<div><strong>Duo:</strong> ${mate ? mate.full_name : 'No teammate (solo)'}</div>`);
  lines.push(`<div><strong>Your location:</strong> ${selfLoc?.latitude != null ? `${selfLoc.latitude.toFixed(5)}, ${selfLoc.longitude.toFixed(5)}` : 'Awaiting location'}</div>`);
  if (mate) {
    lines.push(`<div><strong>Duo location:</strong> ${mateLoc?.latitude != null ? `${mateLoc.latitude.toFixed(5)}, ${mateLoc.longitude.toFixed(5)}` : 'Awaiting teammate location'}</div>`);
  }
  ui.duoInfoPanel.innerHTML = lines.join('');
}

function updateLiveModeClass(dashboard) {
  const live = dashboard?.game_stage === 'live';
  document.body.classList.toggle('live-game-mode', live);
  if (!live) {
    state.liveFullscreenAttempted = false;
    return;
  }
  if (state.liveFullscreenAttempted) return;
  state.liveFullscreenAttempted = true;
  if (ui.userGameShell?.requestFullscreen) {
    ui.userGameShell.requestFullscreen().catch(() => {});
  }
}

function renderDashboard() {
  const dashboard = state.dashboard;
  if (!dashboard) return;

  const role = dashboard.role || 'hider';
  ui.dashboardRoleLine.textContent = `You are a: ${role}`;
  ui.dashboardStatusLine.textContent = `Stage: ${dashboard.game_stage} • Phase: ${dashboard.clock?.phase || 'idle'} • ${dashboard.game_info || ''}`;

  const stats = dashboard.stats || { in: 0, seeker: 0, eliminated: 0 };
  ui.statInCount.textContent = String(stats.in || 0);
  ui.statSeekerCount.textContent = String(stats.seeker || 0);
  ui.statEliminatedCount.textContent = String(stats.eliminated || 0);

  const clock = dashboard.clock || { seconds: 0, direction: 'down' };
  state.clockRenderSeconds = Math.max(0, Number(clock.seconds || 0));
  state.clockDirection = clock.direction === 'up' ? 'up' : 'down';
  ui.gameClockValue.textContent = formatClock(state.clockRenderSeconds);
  startClockTick();

  renderPersistentAlerts(dashboard.active_alerts || []);
  renderDuoInfo(dashboard);
  renderKillboard(dashboard.killboard || {});
  updateUserMap(dashboard);
  updateLiveModeClass(dashboard);
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
    stopClockTick();
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
  const paymentStatus = state.user.payment_status || 'pending';
  ui.paymentInfo.innerHTML = `<div class="small text-muted">Mode: ${state.user.mode || 'Not selected'}</div><div class="small mt-1">Payment status: ${paymentStatus}</div><div>Teammate: ${state.matchmaking?.teammate ? state.matchmaking.teammate.full_name : 'none'}</div>`;

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
    ui.searchResults.innerHTML = '<div class="small text-muted">No results found.</div>';
    return;
  }

  results.forEach((row) => {
    const item = document.createElement('div');
    item.className = 'list-group-item list-group-item-action';
    item.innerHTML = `
      <div class="d-flex justify-content-between align-items-center gap-2">
        <div><div class="fw-semibold">${row.full_name}</div><small class="text-muted">${row.graduation_year} • ${row.concentration}</small></div>
        <button class="btn btn-sm btn-primary">Invite</button>
      </div>
    `;
    item.querySelector('button').addEventListener('click', async () => {
      try {
        await api('send_invite', { invitee_user_id: row.id });
        toast(`Invite sent to ${row.full_name}`, 'success');
        await loadMatchmakingState();
      } catch (error) {
        toast(error.message, 'danger');
      }
    });
    ui.searchResults.appendChild(item);
  });
}

function renderIncoming(incoming) {
  ui.incomingInvites.textContent = '';
  if (!incoming.length) {
    ui.incomingInvites.innerHTML = '<div class="small text-muted">No incoming invites.</div>';
    return;
  }
  incoming.forEach((invite) => {
    const card = document.createElement('div');
    card.className = 'invite-card';
    card.innerHTML = `<div class="fw-semibold mb-2">${invite.inviter_full_name} invited you</div><div class="d-flex gap-2"><button class="btn btn-success btn-sm">Accept</button><button class="btn btn-outline-light btn-sm">Decline</button></div>`;
    const [accept, decline] = card.querySelectorAll('button');
    accept.addEventListener('click', () => replyInvite(invite.id, 'accept'));
    decline.addEventListener('click', () => replyInvite(invite.id, 'decline'));
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
    state.matchmaking = { incoming: data.incoming, outgoing: data.outgoing, teammate: data.teammate };
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
    if (!state.pollBusy) toast(error.message, 'danger');
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
    // silent polling errors
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

function toggleFullscreen(element) {
  if (!element) return;
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {});
    return;
  }
  if (element.requestFullscreen) element.requestFullscreen().catch(() => {});
}

function wireEvents() {
  document.querySelectorAll('[data-auth-tab]').forEach((btn) => btn.addEventListener('click', () => setAuthTab(btn.dataset.authTab)));

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
      stopClockTick();
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

  ui.openIncidentModalBtn?.addEventListener('click', async () => {
    if (document.fullscreenElement && document.fullscreenElement !== document.documentElement) {
      try {
        await document.exitFullscreen();
      } catch (_) {
        // ignore
      }
    }
    if (window.bootstrap?.Modal) {
      const modal = bootstrap.Modal.getOrCreateInstance(ui.incidentModal);
      modal.show();
    }
  });

  ui.incidentForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.incidentForm).entries());
      await api('report_incident', payload, { loadingText: 'Submitting incident...' });
      ui.incidentForm.reset();
      if (window.bootstrap?.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(ui.incidentModal);
        modal.hide();
      }
      toast('Incident report sent to admins.', 'danger');
      await refreshState(true);
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.withdrawBtn?.addEventListener('click', async () => {
    const confirmed = window.confirm('Withdraw from the game? You will remain withdrawn until admins reset the game.');
    if (!confirmed) return;
    try {
      const data = await api('withdraw_game', {}, { loadingText: 'Withdrawing...' });
      state.user = data.user;
      await refreshState();
      toast('You are now withdrawn from the game.', 'warning');
    } catch (error) {
      toast(error.message, 'danger');
    }
  });

  ui.userMapFullscreenBtn?.addEventListener('click', () => toggleFullscreen(ui.userMap?.parentElement?.parentElement));
  ui.userKillboardFullscreenBtn?.addEventListener('click', () => toggleFullscreen(ui.killboardCards?.parentElement?.parentElement));
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
