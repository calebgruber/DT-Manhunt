const state = {
  admin: window.__BOOT_ADMIN__ || null,
  pollTimer: null,
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
    const userA = document.createElement('div');
    userA.className = 'fw-semibold';
    userA.textContent = match.user_a_name;
    const userB = document.createElement('div');
    userB.className = 'fw-semibold';
    userB.textContent = match.user_b_name;
    pairTd.append(userA, userB);

    const paymentTd = document.createElement('td');
    const paymentA = document.createElement('div');
    paymentA.textContent = match.user_a_payment;
    const paymentB = document.createElement('div');
    paymentB.textContent = match.user_b_payment;
    paymentTd.append(paymentA, paymentB);

    const stepTd = document.createElement('td');
    const stepA = document.createElement('div');
    stepA.textContent = match.user_a_step;
    const stepB = document.createElement('div');
    stepB.textContent = match.user_b_step;
    stepTd.append(stepA, stepB);

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

async function refreshAdminData() {
  if (!state.admin) return;
  const [adminState, payments, matches] = await Promise.all([
    api('admin_state'),
    api('admin_list_payments'),
    api('admin_list_matches'),
  ]);
  state.admin = adminState.admin;
  ui.venmoLink.value = adminState.venmo_link || '';
  renderPayments(payments.payments || []);
  renderMatches(matches.matches || []);
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
