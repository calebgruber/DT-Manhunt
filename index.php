<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$user = currentUser();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DT Manhunt</title>
    <link href="https://cdn.jsdelivr.net/gh/ayroui/bootstrap-ui-components@96a9f7dc00bd6d2bcaedf11c163fec2ededfbb2c/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/gh/ayroui/bootstrap-ui-components@96a9f7dc00bd6d2bcaedf11c163fec2ededfbb2c/assets/scss/starter.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/style.css" rel="stylesheet">
</head>
<body class="dark-ui">
<div class="container py-3 py-lg-4 app-shell">
    <header class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 m-0">DT Manhunt</h1>
        <button id="logoutBtn" class="btn btn-outline-primary btn-sm d-none">Logout</button>
    </header>

    <div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <section id="authSection" class="card border-0 shadow-sm auth-card">
        <div class="card-body p-3 p-lg-4">
            <div class="auth-layout">
                <div class="auth-brand d-none d-lg-flex">
                    <div>
                        <span class="badge bg-primary-subtle text-primary-emphasis mb-3">Modern UI Kit</span>
                        <h2 class="h4 mb-2">Register for the Hunt</h2>
                        <p class="text-body-secondary mb-0">Desktop shows a split layout while mobile stays stacked and touch-first.</p>
                    </div>
                </div>
                <div>
            <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-auth-tab="login">Login</button></li>
                <li class="nav-item"><button class="nav-link" data-auth-tab="register">Register</button></li>
            </ul>

            <div id="loginPanel" class="auth-panel">
                <h2 class="h5">Login</h2>
                <p class="text-secondary small">Use your phone number and PIN. Your progress will resume automatically.</p>
                <form id="loginForm" class="vstack gap-2">
                    <input class="form-control form-control-lg" name="phone" placeholder="Phone number" required>
                    <input class="form-control form-control-lg" name="pin" placeholder="PIN" required type="password" inputmode="numeric">
                    <button class="btn btn-primary btn-lg" type="submit">Sign In</button>
                </form>
            </div>

            <div id="registerPanel" class="auth-panel d-none">
                <h2 class="h5">Register</h2>
                <p class="text-secondary small">Create your account first, then choose solo/duo and continue.</p>
                <form id="registerForm" class="vstack gap-2">
                    <div class="row g-2">
                        <div class="col-6"><input class="form-control form-control-lg" name="first_name" placeholder="First name" required></div>
                        <div class="col-6"><input class="form-control form-control-lg" name="last_name" placeholder="Last name" required></div>
                    </div>
                    <input class="form-control form-control-lg" name="phone" placeholder="Phone number" required>
                    <input class="form-control form-control-lg" name="pin" placeholder="PIN (4-8 digits)" required type="password" inputmode="numeric">
                    <div class="row g-2">
                        <div class="col-6"><input class="form-control form-control-lg" name="graduation_year" placeholder="Grad year" required inputmode="numeric"></div>
                        <div class="col-6"><input class="form-control form-control-lg" name="concentration" placeholder="Concentration" required></div>
                    </div>
                    <button class="btn btn-success btn-lg" type="submit">Create Account</button>
                </form>
            </div>
                </div>
            </div>
        </div>
    </section>

    <section id="appSection" class="d-none">
        <div class="app-layout">
            <aside class="app-sidebar">
                <div id="stepperCard" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="stepper" id="stepper"></div>
                    </div>
                </div>
                <div id="resumeCard" class="alert alert-info d-none mb-3"></div>
            </aside>

            <div class="app-main">
                <div id="profileStep" class="card border-0 shadow-sm d-none step-card">
                    <div class="card-body">
                        <h2 class="h5">Profile Saved</h2>
                        <div id="profileSummary" class="small text-secondary"></div>
                        <button class="btn btn-primary btn-lg w-100 mt-3" id="continueToMode">Continue to Mode</button>
                    </div>
                </div>

                <div id="modeStep" class="card border-0 shadow-sm d-none step-card">
                    <div class="card-body">
                        <h2 class="h5">Choose Game Mode</h2>
                        <p class="text-secondary small">Tap one option.</p>
                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <button class="btn btn-outline-primary mode-btn w-100 py-4" data-mode="solo">
                                    <i class="bi bi-person-fill d-block fs-2"></i>
                                    <span class="fw-semibold">Solo</span>
                                </button>
                            </div>
                            <div class="col-12 col-md-6">
                                <button class="btn btn-outline-warning mode-btn w-100 py-4" data-mode="duo">
                                    <i class="bi bi-people-fill d-block fs-2"></i>
                                    <span class="fw-semibold">Duo</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="matchmakingStep" class="card border-0 shadow-sm d-none step-card">
                    <div class="card-body">
                        <h2 class="h5">Find Teammate</h2>
                        <p class="text-secondary small">Search by first and last name and send an invite.</p>
                        <div class="matchmaking-layout">
                            <div>
                                <div class="input-group mb-2">
                                    <input id="searchInput" class="form-control form-control-lg" placeholder="Search full name">
                                    <button id="searchBtn" class="btn btn-primary">Search</button>
                                </div>
                                <div id="searchResults" class="list-group mb-3"></div>
                                <h3 class="h6">Invite Status</h3>
                                <div id="outgoingInvite" class="small text-secondary mb-3">No outgoing invite.</div>
                            </div>
                            <div>
                                <h3 class="h6">Incoming Invites</h3>
                                <div id="incomingInvites" class="vstack gap-2"></div>
                                <div id="teammateCard" class="alert alert-success d-none mt-3"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="paymentStep" class="card border-0 shadow-sm d-none step-card">
                    <div class="card-body">
                        <h2 class="h5">Payment</h2>
                        <p class="text-secondary small">Complete payment to finish registration.</p>
                        <div id="paymentInfo" class="mb-3"></div>
                        <button class="btn btn-success btn-lg w-100" id="completePaymentBtn">Mark Payment Complete</button>
                    </div>
                </div>

                <div id="completeStep" class="card border-0 shadow-sm d-none step-card complete-card">
                    <div class="card-body">
                        <h2 class="h5 text-success">Registration Complete</h2>
                        <p class="mb-0">You are all set for DT Manhunt.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
window.__BOOT_USER__ = <?php echo json_encode(
    $user ? userPublic($user) : null,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/app.js"></script>
</body>
</html>
