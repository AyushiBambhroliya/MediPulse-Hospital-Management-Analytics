
<?php
require_once __DIR__ . '/config.php';

// Get live statistics from MediPulse database
$stats = [
    'patients' => 0,
    'doctors' => 0,
    'departments' => 0,
    'appointments' => 0
];

try {
    $stats['patients'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM patients"
    )->fetchColumn();

    $stats['doctors'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM doctors"
    )->fetchColumn();

    $stats['departments'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM departments"
    )->fetchColumn();

    $stats['appointments'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM appointments"
    )->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>MediPulse | Hospital Analytics Dashboard</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --bg: #071321;
    --panel: #10243a;
    --panel-light: #142d45;
    --cyan: #23d5e6;
    --teal: #18b9a6;
    --white: #f5fbff;
    --muted: #94a9bd;
    --border: rgba(255,255,255,.09);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--white);
    overflow-x: hidden;
}

a {
    text-decoration: none;
    color: inherit;
}

.container {
    width: min(1160px, 90%);
    margin: auto;
}

/* NAVBAR */

.navbar {
    height: 82px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(7,19,33,.94);
    backdrop-filter: blur(15px);
}

.nav-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.logo {
    display: flex;
    align-items: center;
    gap: 11px;
}

.logo-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    color: #071321;
    background: linear-gradient(135deg, var(--cyan), var(--teal));
    font-size: 25px;
    font-weight: 800;
}

.logo h2 {
    font-family: Outfit, sans-serif;
    font-size: 24px;
}

.logo span {
    color: var(--cyan);
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 32px;
}

.nav-links a {
    color: var(--muted);
    font-size: 14px;
    transition: .3s;
}

.nav-links a:hover {
    color: var(--cyan);
}

.login-btn {
    padding: 12px 25px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--cyan), var(--teal));
    color: #061522 !important;
    font-weight: 800;
    transition: .3s;
}

.login-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(35,213,230,.2);
}

/* HERO */

.hero {
    padding: 95px 0 85px;
    position: relative;
}

.hero::before {
    content: "";
    position: absolute;
    width: 450px;
    height: 450px;
    border-radius: 50%;
    background: rgba(35,213,230,.09);
    filter: blur(110px);
    top: 30px;
    right: 5%;
    pointer-events: none;
}

.hero-grid {
    display: grid;
    grid-template-columns: 1fr 1.05fr;
    align-items: center;
    gap: 55px;
    position: relative;
}

.tag {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 9px 15px;
    border: 1px solid rgba(35,213,230,.3);
    background: rgba(35,213,230,.07);
    border-radius: 30px;
    color: var(--cyan);
    font-size: 12px;
    letter-spacing: 1px;
    margin-bottom: 25px;
}

.dot {
    height: 7px;
    width: 7px;
    background: var(--teal);
    border-radius: 50%;
    box-shadow: 0 0 10px var(--teal);
}

.hero h1 {
    font-family: Outfit, sans-serif;
    font-size: clamp(40px, 5vw, 65px);
    line-height: 1.13;
    letter-spacing: -2px;
    margin-bottom: 22px;
}

.hero h1 span {
    color: var(--cyan);
}

.hero-text {
    max-width: 490px;
    color: var(--muted);
    line-height: 1.9;
    font-size: 15px;
}

.hero-buttons {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin-top: 32px;
}

.primary-btn, .secondary-btn {
    padding: 15px 24px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 14px;
    transition: .3s;
}

.primary-btn {
    background: linear-gradient(135deg, var(--cyan), var(--teal));
    color: #071321;
}

.secondary-btn {
    border: 1px solid var(--border);
    color: var(--white);
}

.primary-btn:hover, .secondary-btn:hover {
    transform: translateY(-3px);
}

/* DASHBOARD PREVIEW */

.preview {
    background: linear-gradient(145deg, #142d45, #0c1e31);
    border: 1px solid rgba(35,213,230,.2);
    border-radius: 19px;
    padding: 23px;
    box-shadow: 0 25px 70px rgba(0,0,0,.35);
    transform: perspective(1000px) rotateY(-4deg);
}

.preview-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 23px;
}

.preview-top h3 {
    font-size: 16px;
}

.live {
    font-size: 11px;
    color: var(--teal);
    background: rgba(24,185,166,.1);
    padding: 7px 11px;
    border-radius: 20px;
}

.preview-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 13px;
}

.mini-card {
    background: rgba(255,255,255,.045);
    border: 1px solid var(--border);
    padding: 17px;
    border-radius: 12px;
}

.mini-card p {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 12px;
}

.mini-card h2 {
    font-size: 26px;
    font-family: Outfit, sans-serif;
}

.mini-card span {
    color: var(--teal);
    font-size: 11px;
    display: block;
    margin-top: 7px;
}

.chart-box {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: rgba(255,255,255,.025);
}

.chart-heading {
    display: flex;
    justify-content: space-between;
    margin-bottom: 15px;
    font-size: 13px;
}

.chart-heading span {
    color: var(--muted);
}

.chart-wrap {
    height: 160px;
    position: relative;
}

/* SECTION COMMON */

.section {
    padding: 80px 0;
}

.section-heading {
    text-align: center;
    margin-bottom: 45px;
}

.section-heading h2 {
    font-family: Outfit, sans-serif;
    font-size: 36px;
    margin-bottom: 13px;
}

.section-heading h2 span {
    color: var(--cyan);
}

.section-heading p {
    color: var(--muted);
    font-size: 14px;
}

/* STATS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}

.stat-card {
    padding: 28px 20px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 13px;
    transition: .3s;
}

.stat-card:hover {
    border-color: rgba(35,213,230,.4);
    transform: translateY(-5px);
}

.stat-icon {
    font-size: 23px;
    margin-bottom: 18px;
}

.stat-card h3 {
    font-family: Outfit, sans-serif;
    font-size: 31px;
    color: var(--cyan);
}

.stat-card p {
    color: var(--muted);
    margin-top: 7px;
    font-size: 13px;
}

/* FEATURES */

.features {
    background: rgba(255,255,255,.015);
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.feature-card {
    padding: 28px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    transition: .3s;
}

.feature-card:hover {
    transform: translateY(-5px);
    border-color: rgba(35,213,230,.4);
}

.feature-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(35,213,230,.1);
    display: grid;
    place-items: center;
    font-size: 23px;
    margin-bottom: 20px;
}

.feature-card h3 {
    font-size: 17px;
    margin-bottom: 12px;
}

.feature-card p {
    color: var(--muted);
    line-height: 1.8;
    font-size: 13px;
}

/* ABOUT */

.about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 50px;
    align-items: center;
}

.about-box {
    padding: 30px;
    border-radius: 16px;
    background: linear-gradient(145deg, #142d45, #0b1d30);
    border: 1px solid var(--border);
}

.about-box h3 {
    color: var(--cyan);
    font-family: Outfit, sans-serif;
    font-size: 23px;
    margin-bottom: 15px;
}

.about-box p, .about-text p {
    color: var(--muted);
    line-height: 1.9;
    font-size: 14px;
}

.about-text h2 {
    font-family: Outfit, sans-serif;
    font-size: 34px;
    margin-bottom: 20px;
}

/* CTA */

.cta {
    padding: 65px 25px;
    text-align: center;
    background: linear-gradient(120deg, #102e43, #103b48);
    border: 1px solid rgba(35,213,230,.2);
    border-radius: 18px;
}

.cta h2 {
    font-family: Outfit, sans-serif;
    font-size: 32px;
    margin-bottom: 15px;
}

.cta p {
    color: var(--muted);
    margin-bottom: 28px;
    font-size: 14px;
}

/* FOOTER */

footer {
    border-top: 1px solid var(--border);
    padding: 30px 0;
    margin-top: 80px;
}

.footer-content {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
    color: var(--muted);
    font-size: 12px;
}

/* MOBILE */

@media(max-width: 900px) {
    .hero-grid {
        grid-template-columns: 1fr;
    }

    .preview {
        transform: none;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .features-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 600px) {
    .navbar {
        height: auto;
        padding: 17px 0;
    }

    .nav-content {
        flex-wrap: wrap;
        gap: 15px;
    }

    .nav-links {
        width: 100%;
        gap: 16px;
        flex-wrap: wrap;
    }

    .nav-links a {
        font-size: 12px;
    }

    .hero {
        padding: 60px 0;
    }

    .hero h1 {
        font-size: 42px;
    }

    .stats-grid, .features-grid, .about-grid {
        grid-template-columns: 1fr;
    }

    .section-heading h2 {
        font-size: 29px;
    }

    .preview-cards {
        grid-template-columns: repeat(2, 1fr);
    }

    .footer-content {
        flex-direction: column;
    }
}
</style>
</head>

<body>

<!-- NAVBAR -->

<header class="navbar">
    <div class="container nav-content">

        <a href="index.php" class="logo">
            <div class="logo-icon">M</div>
            <h2>Medi<span>Pulse</span></h2>
        </a>

        <nav class="nav-links">
            <a href="#home">Home</a>
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#statistics">Statistics</a>
            <a href="#contact">Contact</a>
            <a href="login/login.php" class="login-btn">Login ↗</a>
        </nav>

    </div>
</header>


<!-- HERO -->

<main>

<section class="hero" id="home">
<div class="container hero-grid">

    <div class="hero-content">

        <div class="tag">
            <span class="dot"></span>
            HOSPITAL ANALYTICS PLATFORM
        </div>

        <h1>
            Transforming<br>
            Hospital Data<br>
            Into <span>Insights.</span>
        </h1>

        <p class="hero-text">
            Discover meaningful insights from hospital data.
            Monitor patient trends, explore appointment patterns,
            analyse doctor performance and understand financial
            reports through one powerful analytics platform.
        </p>

        <div class="hero-buttons">
            <a href="login/login.php" class="primary-btn">
                Login to Dashboard →
            </a>

            <a href="#features" class="secondary-btn">
                Explore Features
            </a>
        </div>

    </div>


    <!-- DASHBOARD PREVIEW -->

    <div class="preview">

        <div class="preview-top">
            <h3>Analytics Overview</h3>
            <span class="live">● DATABASE CONNECTED</span>
        </div>

        <div class="preview-cards">

            <div class="mini-card">
                <p>Total Patients</p>
                <h2><?php echo number_format($stats['patients']); ?></h2>
                <span>● Database records</span>
            </div>

            <div class="mini-card">
                <p>Total Doctors</p>
                <h2><?php echo number_format($stats['doctors']); ?></h2>
                <span>● Registered doctors</span>
            </div>

            <div class="mini-card">
                <p>Departments</p>
                <h2><?php echo number_format($stats['departments']); ?></h2>
                <span>● Available departments</span>
            </div>

            <div class="mini-card">
                <p>Appointments</p>
                <h2><?php echo number_format($stats['appointments']); ?></h2>
                <span>● Total records</span>
            </div>

        </div>

        <div class="chart-box">
            <div class="chart-heading">
                <strong>Appointment Activity</strong>
                <span>Monthly overview</span>
            </div>

            <div class="chart-wrap">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

    </div>

</div>
</section>


<!-- STATISTICS -->

<section class="section" id="statistics">
<div class="container">

    <div class="section-heading">
        <h2>Hospital Data <span>At A Glance</span></h2>
        <p>Explore the data available in the MediPulse platform.</p>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <h3><?php echo number_format($stats['patients']); ?>+</h3>
            <p>Total Patients</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🩺</div>
            <h3><?php echo number_format($stats['doctors']); ?></h3>
            <p>Total Doctors</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🏥</div>
            <h3><?php echo number_format($stats['departments']); ?></h3>
            <p>Departments</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <h3><?php echo number_format($stats['appointments']); ?>+</h3>
            <p>Appointments</p>
        </div>

    </div>
</div>
</section>


<!-- FEATURES -->

<section class="section features" id="features">
<div class="container">

    <div class="section-heading">
        <h2>Powerful Analytics <span>Features</span></h2>
        <p>Explore different areas of hospital performance.</p>
    </div>

    <div class="features-grid">

        <div class="feature-card">
            <div class="feature-icon">👥</div>
            <h3>Patient Analytics</h3>
            <p>Explore patient demographics, age groups,
            gender distribution and registration trends.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">📅</div>
            <h3>Appointment Analysis</h3>
            <p>Understand appointment patterns, status
            distribution and waiting-time trends.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🩺</div>
            <h3>Doctor Performance</h3>
            <p>Analyse doctor experience, specializations
            and appointment statistics.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🏥</div>
            <h3>Department Insights</h3>
            <p>Compare department information and
            explore hospital department data.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">💳</div>
            <h3>Billing Analytics</h3>
            <p>Understand billing amounts, payment
            methods and payment status.</p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">📈</div>
            <h3>Reports & Visualization</h3>
            <p>Present meaningful information through
            charts, graphs and analytical reports.</p>
        </div>

    </div>
</div>
</section>


<!-- ABOUT -->

<section class="section" id="about">
<div class="container about-grid">

    <div class="about-text">
        <div class="tag">ABOUT MEDIPULSE</div>

        <h2>Making Hospital Data Easier To Understand</h2>

        <p>
            MediPulse is a hospital analytics platform designed
            to organize and visualize hospital datasets in a
            meaningful way.
        </p>

        <br>

        <p>
            It helps users explore patient information,
            appointments, doctor statistics, departments,
            billing and payment records through data-driven
            insights and reports.
        </p>
    </div>

    <div class="about-box">
        <h3>One Platform. Meaningful Insights.</h3>

        <p>
            From patient trends to financial summaries,
            MediPulse brings different hospital datasets
            together to support better understanding of
            hospital operations.
        </p>
    </div>

</div>
</section>


<!-- CTA -->

<section class="container" id="contact">
    <div class="cta">

        <h2>Ready to Explore Hospital Analytics?</h2>

        <p>
            Sign in to MediPulse and explore hospital
            data through meaningful insights.
        </p>

        <a href="login/login.php" class="primary-btn">
            Get Started →
        </a>

    </div>
</section>

</main>


<!-- FOOTER -->

<footer>
<div class="container footer-content">

    <div>
        © 2026 MediPulse. All rights reserved.
    </div>

    <div>
        Hospital Analytics Dashboard
    </div>

</div>
</footer>


<!-- CHART -->

<script>
const ctx = document.getElementById('activityChart');

if (ctx) {
    new Chart(ctx, {
        type: 'line',

        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],

            datasets: [{
                label: 'Illustrative activity',
                data: [12, 19, 15, 25, 22, 34],
                borderColor: '#23d5e6',
                backgroundColor: 'rgba(35,213,230,0.12)',
                fill: true,
                tension: 0.4,
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: '#18b9a6'
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                }
            },

            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#94a9bd'
                    }
                },

                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255,255,255,.06)'
                    },
                    ticks: {
                        color: '#94a9bd'
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
