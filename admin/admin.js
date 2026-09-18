const state = {
  admin: window.__BOOT_ADMIN__ || null,
  pollTimer: null,
  map: null,
  mapMarkers: new Map(),
};

const ui = {
  adminLogin: document.getElementById('adminLogin'),
  adminDashboard: document.getElementById('adminDashboard'),
  adminNotice: document.getElementById('adminNotice'),
  adminLoginForm: document.getElementById('adminLoginForm'),
  adminLogoutBtn: document.getElementById('adminLogoutBtn'),
  incidentBanner: document.getElementById('incidentBanner'),
  venmoForm: document.getElementById('venmoForm'),
  venmoLink: document.getElementById('venmoLink'),
  paymentsBody: document.getElementById('paymentsBody'),
  matchesBody: document.getElementById('matchesBody'),
  gameStateForm: document.getElementById('gameStateForm'),
  gameStage: document.getElementById('gameStage'),
  clockMode: document.getElementById('clockMode'),
  hideDurationSeconds: document.getElementById('hideDurationSeconds'),
  seekDurationSeconds: document.getElementById('seekDurationSeconds'),
  announcementText: document.getElementById('announcementText'),
  gameInfoText: document.getElementById('gameInfoText'),
  startGameBtn: document.getElementById('startGameBtn'),
  resetGameBtn: document.getElementById('resetGameBtn'),
  messageForm: document.getElementById('messageForm'),
  messageTarget: document.getElementById('messageTarget'),
  messageUserId: document.getElementById('messageUserId'),
  messageGroupIds: document.getElementById('messageGroupIds'),
  messageText: document.getElementById('messageText'),
  messagesFeed: document.getElementById('messagesFeed'),
  locationsBody: document.getElementById('locationsBody'),
  adminMapFullscreenBtn: document.getElementById('adminMapFullscreenBtn'),
  adminKillboardFullscreenBtn: document.getElementById('adminKillboardFullscreenBtn'),
  killboardSummary: document.getElementById('killboardSummary'),
  killboardCards: document.getElementById('killboardCards'),
  killboardBody: document.getElementById('killboardBody'),
  incidentsBody: document.getElementById('incidentsBody'),
};

function notice(message, type = 'info') {
  ui.adminNotice.className = `alert alert-${type} mt-3`;
  ui.adminNotice.textContent = String(message);
  ui.adminNotice.classList.remove('d-none');
}

function setTabs(tab) {
  document.querySelectorAll('[data-admin-tab]').forEach((btn) => {
    const active = btn.dataset.adminTab === tab;
    btn.classList.toggle('active', active);
  });
  document.querySelectorAll('[data-admin-panel]').forEach((panel) => {
    panel.classList.toggle('d-none', panel.dataset.adminPanel !== tab);
  });
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

function ensureMap() {
  if (state.map || !window.L) return;
  state.map = L.map('liveMap').setView([41.04, -73.7], 14);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap &copy; CARTO',
  }).addTo(state.map);
}

function toggleFullscreen(element) {
  if (!element) return;
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {});
  } else if (element.requestFullscreen) {
    element.requestFullscreen().catch(() => {});
  }
}

function renderPayments(payments) {
  ui.paymentsBody.textContent = '';
  if (!payments.length) {
    ui.paymentsBody.innerHTML = '<tr><td colspan="4" class="text-secondary">No payment approvals pending.</td></tr>';
    return;
  }

  payments.forEach((payment) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><div class="fw-semibold">${payment.full_name}</div><small class="text-secondary">${payment.phone}</small></td>
      <td>${payment.payment_status}</td>
      <td>${payment.registration_step}</td>
      <td></td>
    `;
    const actions = tr.children[3];

    const approveBtn = document.createElement('button');
    approveBtn.className = 'btn btn-sm btn-success me-2';
    approveBtn.textContent = 'Approve';
    approveBtn.disabled = payment.payment_status === 'approved';
    approveBtn.addEventListener('click', async () => {
      try {
        await api('admin_approve_payment', { user_id: payment.id });
        notice(`Approved payment for ${payment.full_name}`, 'success');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    const resetBtn = document.createElement('button');
    resetBtn.className = 'btn btn-sm btn-outline-warning';
    resetBtn.textContent = 'Reset';
    resetBtn.disabled = payment.payment_status === 'pending';
    resetBtn.addEventListener('click', async () => {
      try {
        await api('admin_reset_payment', { user_id: payment.id });
        notice(`Reset payment for ${payment.full_name}`, 'success');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    actions.append(approveBtn, resetBtn);
    ui.paymentsBody.appendChild(tr);
  });
}

function renderMatches(matches) {
  ui.matchesBody.textContent = '';
  if (!matches.length) {
    ui.matchesBody.innerHTML = '<tr><td colspan="4" class="text-secondary">No active matched duos.</td></tr>';
    return;
  }

  matches.forEach((match) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><div class="fw-semibold">${match.user_a_name}</div><div class="fw-semibold">${match.user_b_name}</div></td>
      <td><div>${match.user_a_payment}</div><div>${match.user_b_payment}</div></td>
      <td><div>${match.user_a_step}</div><div>${match.user_b_step}</div></td>
      <td></td>
    `;

    const actions = tr.children[3];
    const resetBtn = document.createElement('button');
    resetBtn.className = 'btn btn-sm btn-outline-warning me-2';
    resetBtn.textContent = 'Reset Match';
    resetBtn.addEventListener('click', async () => {
      try {
        await api('admin_reset_match', { user_id: match.user_a_id });
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    const soloBtn = document.createElement('button');
    soloBtn.className = 'btn btn-sm btn-outline-primary';
    soloBtn.textContent = 'Switch to Solo';
    soloBtn.addEventListener('click', async () => {
      try {
        await api('admin_switch_match_to_solo', { user_id: match.user_a_id });
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    actions.append(resetBtn, soloBtn);
    ui.matchesBody.appendChild(tr);
  });
}

function renderMessages(messages) {
  ui.messagesFeed.textContent = '';
  if (!messages.length) {
    ui.messagesFeed.innerHTML = '<div class="text-secondary small">No live messages.</div>';
    return;
  }

  messages.forEach((message) => {
    const row = document.createElement('div');
    row.className = 'border p-2';
    row.innerHTML = `
      <div class="d-flex justify-content-between gap-2 mb-1">
        <strong>${message.recipient_scope}</strong>
        <small class="text-secondary">${new Date(message.created_at).toLocaleString()}</small>
      </div>
      <div class="mb-1">${message.body}</div>
      <small class="text-secondary">Recipients: ${message.recipient_count}</small>
      <div class="mt-2"><button class="btn btn-sm btn-outline-danger">Remove</button></div>
    `;
    row.querySelector('button')?.addEventListener('click', async () => {
      try {
        await api('admin_delete_message', { message_id: message.id });
        notice('Message removed.', 'warning');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });
    ui.messagesFeed.appendChild(row);
  });
}

function renderLocations(locations) {
  ui.locationsBody.textContent = '';
  ensureMap();
  const seen = new Set();

  locations.forEach((location) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><div class="fw-semibold">${location.full_name}</div><small class="text-secondary">${location.phone}</small></td>
      <td>${location.game_status}</td>
      <td>${location.latitude.toFixed(5)}, ${location.longitude.toFixed(5)}</td>
      <td>${location.location_updated_at ? new Date(location.location_updated_at).toLocaleTimeString() : '—'}</td>
    `;
    ui.locationsBody.appendChild(tr);

    if (state.map) {
      seen.add(location.id);
      const label = `${location.full_name} (${location.game_status})`;
      if (state.mapMarkers.has(location.id)) {
        const marker = state.mapMarkers.get(location.id);
        marker.setLatLng([location.latitude, location.longitude]);
        marker.bindPopup(label);
      } else {
        state.mapMarkers.set(location.id, L.marker([location.latitude, location.longitude]).addTo(state.map).bindPopup(label));
      }
    }
  });

  for (const [id, marker] of state.mapMarkers.entries()) {
    if (!seen.has(id)) {
      marker.remove();
      state.mapMarkers.delete(id);
    }
  }

  if (!locations.length) {
    ui.locationsBody.innerHTML = '<tr><td colspan="4" class="text-secondary">No location updates yet.</td></tr>';
  }
}

function renderKillboard(killboard) {
  const counts = killboard?.counts || { in: 0, seeker: 0, eliminated: 0, withdrawn: 0 };
  const players = killboard?.players || [];

  ui.killboardSummary.textContent = `In: ${counts.in} • Seekers: ${counts.seeker} • Eliminated: ${counts.eliminated} • Withdrawn: ${counts.withdrawn}`;
  ui.killboardCards.textContent = '';
  ui.killboardBody.textContent = '';

  players.forEach((player) => {
    const card = document.createElement('div');
    const className = player.status === 'in' ? 'kb-in' : player.status === 'eliminated' ? 'kb-eliminated' : player.status === 'seeker' ? 'kb-seeker' : 'kb-withdrawn';
    card.className = `kb-card ${className}`;
    card.innerHTML = `<div class="fw-semibold">${player.full_name}</div><div class="small">${player.status}</div>`;
    ui.killboardCards.appendChild(card);

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><div class="fw-semibold">${player.full_name}</div><small class="text-secondary">#${player.id}</small></td>
      <td>${player.mode || 'unset'}</td>
      <td>${player.status}</td>
      <td></td>
    `;

    const actions = tr.children[3];
    ['in', 'seeker', 'eliminated', 'withdrawn'].forEach((status) => {
      const btn = document.createElement('button');
      btn.className = `btn btn-sm me-1 ${player.status === status ? 'btn-primary' : 'btn-outline-secondary'}`;
      btn.textContent = status;
      btn.disabled = player.status === status;
      btn.addEventListener('click', async () => {
        try {
          await api('admin_set_player_status', { user_id: player.id, status });
          await refreshAdminData();
        } catch (error) {
          notice(error.message, 'danger');
        }
      });
      actions.appendChild(btn);
    });

    ui.killboardBody.appendChild(tr);
  });

  if (!players.length) {
    ui.killboardCards.innerHTML = '<div class="text-secondary small">No players found.</div>';
    ui.killboardBody.innerHTML = '<tr><td colspan="4" class="text-secondary">No players found.</td></tr>';
  }
}

function renderIncidents(incidents) {
  ui.incidentsBody.textContent = '';
  if (!incidents.length) {
    ui.incidentsBody.innerHTML = '<tr><td colspan="6" class="text-secondary">No incidents reported.</td></tr>';
    ui.incidentBanner.classList.add('d-none');
    return;
  }

  const emergency = incidents.find((item) => item.status === 'open' && ['high', 'emergency'].includes(item.severity));
  if (emergency) {
    ui.incidentBanner.classList.remove('d-none');
    ui.incidentBanner.textContent = `LIVE INCIDENT ALERT: ${emergency.reporter_name} reported ${emergency.incident_type} (${emergency.severity})`;
  } else {
    ui.incidentBanner.classList.add('d-none');
  }

  incidents.forEach((incident) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><div class="fw-semibold">${incident.reporter_name}</div><small class="text-secondary">#${incident.reporter_user_id}</small></td>
      <td>${incident.incident_type}</td>
      <td>${incident.severity}</td>
      <td>${incident.details}</td>
      <td>${incident.status}</td>
      <td></td>
    `;

    const actions = tr.children[5];
    ['open', 'acknowledged', 'resolved'].forEach((status) => {
      const btn = document.createElement('button');
      btn.className = `btn btn-sm me-1 ${incident.status === status ? 'btn-primary' : 'btn-outline-secondary'}`;
      btn.textContent = status;
      btn.disabled = incident.status === status;
      btn.addEventListener('click', async () => {
        try {
          await api('admin_update_incident_status', { incident_id: incident.id, status });
          await refreshAdminData();
        } catch (error) {
          notice(error.message, 'danger');
        }
      });
      actions.appendChild(btn);
    });

    ui.incidentsBody.appendChild(tr);
  });
}

async function refreshAdminData() {
  if (!state.admin) return;
  const [adminState, payments, matches, messages, locations, incidents, killboard] = await Promise.all([
    api('admin_state'),
    api('admin_list_payments'),
    api('admin_list_matches'),
    api('admin_list_messages'),
    api('admin_list_locations'),
    api('admin_list_incidents'),
    api('admin_list_killboard'),
  ]);

  state.admin = adminState.admin;
  ui.venmoLink.value = adminState.venmo_link || '';
  ui.gameStage.value = adminState.game_stage || 'pregame';
  ui.clockMode.value = adminState.clock_mode || 'countdown';
  ui.hideDurationSeconds.value = adminState.hide_duration_seconds ?? 300;
  ui.seekDurationSeconds.value = adminState.seek_duration_seconds ?? 3600;
  ui.announcementText.value = adminState.announcement || '';
  ui.gameInfoText.value = adminState.game_info || '';

  renderPayments(payments.payments || []);
  renderMatches(matches.matches || []);
  renderMessages(messages.messages || []);
  renderLocations(locations.locations || []);
  renderIncidents(incidents.incidents || []);
  renderKillboard(killboard.killboard || adminState.killboard || {});
}

function setLayout() {
  const loggedIn = !!state.admin;
  ui.adminLogin.classList.toggle('d-none', loggedIn);
  ui.adminDashboard.classList.toggle('d-none', !loggedIn);
}

function startPolling() {
  if (state.pollTimer) return;
  state.pollTimer = setInterval(async () => {
    try {
      await refreshAdminData();
    } catch (_) {
      // silent polling failure
    }
  }, 1500);
}

function stopPolling() {
  if (!state.pollTimer) return;
  clearInterval(state.pollTimer);
  state.pollTimer = null;
}

function wire() {
  document.querySelectorAll('[data-admin-tab]').forEach((btn) => {
    btn.addEventListener('click', () => setTabs(btn.dataset.adminTab));
  });

  ui.adminLoginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.adminLoginForm).entries());
      const data = await api('admin_login', payload);
      state.admin = data.admin;
      setLayout();
      setTabs('game');
      await refreshAdminData();
      startPolling();
      notice('Admin login successful.', 'success');
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.adminLogoutBtn?.addEventListener('click', async () => {
    try {
      await api('admin_logout');
      state.admin = null;
      setLayout();
      stopPolling();
      notice('Logged out.', 'secondary');
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.venmoForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.venmoForm).entries());
      const data = await api('admin_set_venmo_link', payload);
      ui.venmoLink.value = data.venmo_link || '';
      notice('Venmo link saved.', 'success');
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.gameStateForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.gameStateForm).entries());
      await api('admin_set_game_state', payload);
      notice('Game state updated.', 'success');
      await refreshAdminData();
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.startGameBtn?.addEventListener('click', async () => {
    try {
      await api('admin_start_game');
      notice('Game started.', 'success');
      await refreshAdminData();
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.resetGameBtn?.addEventListener('click', async () => {
    const confirmReset = window.confirm('Reset game timer and clear withdrawn states?');
    if (!confirmReset) return;
    try {
      await api('admin_reset_game');
      notice('Game reset complete.', 'warning');
      await refreshAdminData();
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.messageForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.messageForm).entries());
      if (!payload.user_id) delete payload.user_id;
      if (!payload.group_user_ids) delete payload.group_user_ids;
      const data = await api('admin_send_message', payload);
      ui.messageText.value = '';
      notice(`Message sent to ${data.recipient_count} recipients.`, 'success');
      await refreshAdminData();
    } catch (error) {
      notice(error.message, 'danger');
    }
  });

  ui.adminMapFullscreenBtn?.addEventListener('click', () => toggleFullscreen(document.getElementById('liveMap')?.parentElement?.parentElement));
  ui.adminKillboardFullscreenBtn?.addEventListener('click', () => toggleFullscreen(document.getElementById('killboardCards')?.parentElement));
}

async function init() {
  wire();
  setTabs('game');
  setLayout();
  if (state.admin) {
    try {
      await refreshAdminData();
      startPolling();
    } catch (error) {
      notice(error.message, 'danger');
    }
  }
}

init();
