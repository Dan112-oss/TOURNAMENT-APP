<?php
declare(strict_types=1);

// The bare domain has nothing of its own to show — send visitors
// straight to login/register. (Swap the target to
// tournament-view.html?id=... later if you want a public landing
// page instead.)

header('Location: /auth.html');
exit;
