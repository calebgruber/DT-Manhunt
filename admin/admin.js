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
  venmoForm: document.getElementById('venmoForm'),
  venmoLink: document.getElementById('venmoLink'),
  paymentsBody: document.getElementById('paymentsBody'),
  matchesBody: document.getElementById('matchesBody'),
  gameStateForm: document.getElementById('gameStateForm'),
  gameStage: document.getElementById('gameStage'),
  announcementText: document.getElementById('announcementText'),
  gameInfoText: document.getElementById('gameInfoText'),
  messageForm: document.getElementById('messageForm'),
  messageTarget: document.getElementById('messageTarget'),
  messageUserId: document.getElementById('messageUserId'),
  messageGroupIds: document.getElementById('messageGroupIds'),
  messageText: document.getElementById('messageText'),
  messagesFeed: document.getElementById('messagesFeed'),
  locationsBody: document.getElementById('locationsBody'),
  killboardSummary: document.getElementById('killboardSummary'),
  killboardBody: document.getElementById('killboardBody'),
  incidentsBody: document.getElementById('incidentsBody'),
};

function notice(message, type = 'info') {
  ui.adminNotice.className = `alert alert-${type} mt-3`;
  ui.adminNotice.textContent = String(message);
  ui.adminNotice.classList.remove('d-none');
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

function ensureMap() {
  if (state.map || !window.L) {
    return;
  }
  state.map = L.map('liveMap').setView([41.04, -73.7], 14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(state.map);
}

function renderPayments(payments) {
  ui.paymentsBody.textContent = '';
  if (!payments.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="4" class="text-secondary">No payment approvals pending.</td>';
    ui.paymentsBody.appendChild(tr);
    return;
  }

  payments.forEach((payment) => {
    const tr = document.createElement('tr');

    const userTd = document.createElement('td');
    const userName = document.createElement('div');
    userName.className = 'fw-semibold';
    userName.textContent = payment.full_name;
    const userPhone = document.createElement('small');
    userPhone.className = 'text-secondary';
    userPhone.textContent = payment.phone;
    userTd.append(userName, userPhone);

    const statusTd = document.createElement('td');
    statusTd.textContent = payment.payment_status;

    const stepTd = document.createElement('td');
    stepTd.textContent = payment.registration_step;

    const actionTd = document.createElement('td');
    const approveBtn = document.createElement('button');
    approveBtn.className = 'btn btn-sm btn-success me-2';
    approveBtn.type = 'button';
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
    resetBtn.type = 'button';
    resetBtn.textContent = 'Reset to Pending';
    resetBtn.disabled = payment.payment_status === 'pending';
    resetBtn.addEventListener('click', async () => {
      try {
        await api('admin_reset_payment', { user_id: payment.id });
        notice(`Reset payment to pending for ${payment.full_name}`, 'success');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    actionTd.append(approveBtn, resetBtn);
    tr.append(userTd, statusTd, stepTd, actionTd);
    ui.paymentsBody.appendChild(tr);
  });
}

function renderMatches(matches) {
  ui.matchesBody.textContent = '';
  if (!matches.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="4" class="text-secondary">No active matched duos.</td>';
    ui.matchesBody.appendChild(tr);
    return;
  }

  matches.forEach((match) => {
    const tr = document.createElement('tr');

    const pairTd = document.createElement('td');
    pairTd.innerHTML = `<div class="fw-semibold">${match.user_a_name}</div><div class="fw-semibold">${match.user_b_name}</div>`;

    const paymentTd = document.createElement('td');
    paymentTd.innerHTML = `<div>${match.user_a_payment}</div><div>${match.user_b_payment}</div>`;

    const stepTd = document.createElement('td');
    stepTd.innerHTML = `<div>${match.user_a_step}</div><div>${match.user_b_step}</div>`;

    const actionsTd = document.createElement('td');
    const resetBtn = document.createElement('button');
    resetBtn.className = 'btn btn-sm btn-outline-warning me-2';
    resetBtn.type = 'button';
    resetBtn.textContent = 'Reset to Matching';
    resetBtn.addEventListener('click', async () => {
      try {
        await api('admin_reset_match', { user_id: match.user_a_id });
        notice('Match reset to duo matchmaking.', 'success');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    const soloBtn = document.createElement('button');
    soloBtn.className = 'btn btn-sm btn-outline-primary';
    soloBtn.type = 'button';
    soloBtn.textContent = 'Switch Pair to Solo';
    soloBtn.addEventListener('click', async () => {
      try {
        await api('admin_switch_match_to_solo', { user_id: match.user_a_id });
        notice('Pair switched to solo while preserving payment status.', 'success');
        await refreshAdminData();
      } catch (error) {
        notice(error.message, 'danger');
      }
    });

    actionsTd.append(resetBtn, soloBtn);
    tr.append(pairTd, paymentTd, stepTd, actionsTd);
    ui.matchesBody.appendChild(tr);
  });
}

function renderMessages(messages) {
  ui.messagesFeed.textContent = '';
  if (!messages.length) {
    const empty = document.createElement('div');
    empty.className = 'text-secondary small';
    empty.textContent = 'No messages sent yet.';
    ui.messagesFeed.appendChild(empty);
    return;
  }

  messages.forEach((message) => {
    const row = document.createElement('div');
    row.className = 'border rounded p-2';
    row.innerHTML = `
      <div class="d-flex justify-content-between gap-2">
        <strong>${message.recipient_scope}</strong>
        <small class="text-secondary">${new Date(message.created_at).toLocaleString()}</small>
      </div>
      <div>${message.body}</div>
      <small class="text-secondary">Recipients: ${message.recipient_count}</small>
    `;
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
      <td>${location.game_status}${location.is_enrolled ? '' : ' (unenrolled)'}</td>
      <td>${location.latitude.toFixed(5)}, ${location.longitude.toFixed(5)}</td>
      <td>${location.location_updated_at ? new Date(location.location_updated_at).toLocaleTimeString() : '—'}</td>
    `;
    ui.locationsBody.appendChild(tr);

    if (state.map) {
      seen.add(location.id);
      const markerLabel = `${location.full_name} (${location.game_status})`;
      if (state.mapMarkers.has(location.id)) {
        const marker = state.mapMarkers.get(location.id);
        marker.setLatLng([location.latitude, location.longitude]);
        marker.bindPopup(markerLabel);
      } else {
        const marker = L.marker([location.latitude, location.longitude]).addTo(state.map).bindPopup(markerLabel);
        state.mapMarkers.set(location.id, marker);
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
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="4" class="text-secondary">No live locations yet.</td>';
    ui.locationsBody.appendChild(tr);
  }
}

function renderKillboard(killboard) {
  const counts = killboard?.counts || { in: 0, eliminated: 0, out: 0 };
  const players = killboard?.players || [];

  ui.killboardSummary.textContent = `In: ${counts.in} • Eliminated: ${counts.eliminated} • Out: ${counts.out}`;
  ui.killboardBody.textContent = '';

  if (!players.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="4" class="text-secondary">No players found.</td>';
    ui.killboardBody.appendChild(tr);
    return;
  }

  players.forEach((player) => {
    const tr = document.createElement('tr');

    const playerTd = document.createElement('td');
    playerTd.innerHTML = `<div class="fw-semibold">${player.full_name}</div><small class="text-secondary">#${player.id}</small>`;

    const modeTd = document.createElement('td');
    modeTd.textContent = player.mode || 'unset';

    const statusTd = document.createElement('td');
    statusTd.textContent = player.status;

    const actionsTd = document.createElement('td');
    ['in', 'eliminated', 'out'].forEach((status) => {
      const btn = document.createElement('button');
      btn.className = `btn btn-sm me-1 ${player.status === status ? 'btn-primary' : 'btn-outline-secondary'}`;
      btn.type = 'button';
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
      actionsTd.appendChild(btn);
    });

    tr.append(playerTd, modeTd, statusTd, actionsTd);
    ui.killboardBody.appendChild(tr);
  });
}

function renderIncidents(incidents) {
  ui.incidentsBody.textContent = '';
  if (!incidents.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td colspan="6" class="text-secondary">No incidents reported.</td>';
    ui.incidentsBody.appendChild(tr);
    return;
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
      btn.type = 'button';
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
  ui.adminLoginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = Object.fromEntries(new FormData(ui.adminLoginForm).entries());
      const data = await api('admin_login', payload);
      state.admin = data.admin;
      setLayout();
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
      notice('Live game state updated.', 'success');
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
}

async function init() {
  wire();
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
