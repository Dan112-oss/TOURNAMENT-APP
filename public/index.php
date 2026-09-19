<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bracketed — Tournament hosting for FC Mobile & eFootball</title>
  <meta name="description" content="Host and join FC Mobile and eFootball tournaments. Auto-generated fixtures, live standings and brackets, fair dispute resolution." />

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Teko:wght@500;600;700&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="assets/css/main.css" />
  <link rel="stylesheet" href="assets/css/landing.css" />
</head>
<body>
  <nav class="landing-nav">
    <span class="dashboard-brand">Bracketed</span>
    <div class="landing-nav-actions">
      <a class="btn btn-ghost" href="auth.html?tab=login">Log in</a>
      <a class="btn btn-primary" href="auth.html?tab=register">Get started</a>
    </div>
  </nav>

  <!-- Hero -->
  <header class="landing-hero">
    <svg class="landing-hero-bracket" viewBox="0 0 800 400" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
      <path d="M40 80 L160 80 L160 160 L280 160" />
      <path d="M40 240 L160 240 L160 160" />
      <path d="M280 160 L400 160 L400 280" />
    </svg>

    <div class="landing-hero-content">
      <h1 class="landing-hero-title">Run tournaments your squad actually shows up for</h1>
      <p class="landing-hero-sub">
        Host FC Mobile and eFootball tournaments, let players submit and confirm results themselves,
        and watch the table or bracket update live — no spreadsheets, no group-chat chaos.
      </p>
      <div class="landing-hero-actions">
        <a class="btn btn-primary" href="auth.html?tab=register">Create a tournament</a>
        <a class="btn btn-ghost" href="auth.html?tab=register">Join with a code</a>
      </div>
      <p class="landing-hero-note">Free to use. No app download required.</p>
    </div>
  </header>

  <!-- Features -->
  <section class="landing-section">
    <h2 class="landing-section-title">Everything a host needs, built in</h2>
    <p class="landing-section-sub">From bracket generation to disputed results, the platform handles the parts that usually get messy.</p>

    <div class="features-grid">
      <div class="card">
        <div class="feature-card-title">Round-robin or knockout</div>
        <div class="feature-card-desc">Choose a league format or a single-elimination bracket — fixtures generate automatically either way.</div>
      </div>
      <div class="card">
        <div class="feature-card-title">Live standings &amp; bracket</div>
        <div class="feature-card-desc">Anyone can watch the table or bracket update in real time, no login required.</div>
      </div>
      <div class="card">
        <div class="feature-card-title">Player-submitted results</div>
        <div class="feature-card-desc">Players upload their own scores with proof — the opponent confirms, and the table updates itself.</div>
      </div>
      <div class="card">
        <div class="feature-card-title">Fair dispute handling</div>
        <div class="feature-card-desc">Disagreements get flagged for the host to resolve directly — never silently dropped.</div>
      </div>
      <div class="card">
        <div class="feature-card-title">Automatic no-show rules</div>
        <div class="feature-card-desc">Set a walkover or void policy up front, and matches nobody plays resolve themselves.</div>
      </div>
      <div class="card">
        <div class="feature-card-title">Built for mobile football</div>
        <div class="feature-card-desc">Designed specifically around FC Mobile and eFootball competitions.</div>
      </div>
    </div>
  </section>

  <!-- How it works -->
  <section class="landing-section">
    <h2 class="landing-section-title">How it works</h2>
    <p class="landing-section-sub">Three steps, whether you're running the tournament or playing in it.</p>

    <div class="steps">
      <div class="step">
        <div class="step-number">1</div>
        <div class="step-title">Create or join</div>
        <div class="step-desc">Hosts set up a tournament and get a join code. Players enter that code to sign up.</div>
      </div>
      <div class="step">
        <div class="step-number">2</div>
        <div class="step-title">Play &amp; submit</div>
        <div class="step-desc">Players play their match, then submit the result with a screenshot. The opponent confirms it.</div>
      </div>
      <div class="step">
        <div class="step-number">3</div>
        <div class="step-title">Watch it update</div>
        <div class="step-desc">The standings table or bracket updates automatically — live, for anyone watching.</div>
      </div>
    </div>
  </section>

  <!-- Closing CTA -->
  <section class="landing-cta">
    <h2 class="landing-section-title">Ready to run your first tournament?</h2>
    <div class="landing-hero-actions" style="margin-top: var(--space-4);">
      <a class="btn btn-primary" href="auth.html?tab=register">Get started free</a>
    </div>
  </section>

  <footer class="landing-footer">
    Bracketed
  </footer>
</body>
</html>
