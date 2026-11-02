<?php
/*
 * Config for muse-mail-bridge.php — SAMPLE.
 *
 * Create the real file as "mail-bridge-config.php" in the directory ONE
 * LEVEL ABOVE the web root (the account home directory, e.g. ~/), never
 * inside www/ where it would be web-accessible. The bridge script looks
 * for it at ../mail-bridge-config.php relative to itself.
 *
 * Fill in the two CHANGE-ME values yourself. Do not send either value to
 * anyone, including Muse: the mailbox password stays on this server, and
 * the same bridge token goes into Muse's connector credential slot via
 * the secure registration page.
 */
return [
    // Long random string you invent (32+ characters recommended).
    // This is the API token for the bridge; the identical value must be
    // entered in the Muse connector slot for custom.sakura-mail.
    'token' => 'CHANGE-ME-long-random-token',

    // The mailbox login. The user is the full address.
    'mail_user' => 'you@your-domain.example',

    // The mailbox password, exactly as set in Sakura's control panel.
    'mail_pass' => 'CHANGE-ME-mailbox-password',

    // Mail server (Sakura initial domain). Normally no need to change.
    'imap_host' => 'your-initial-domain.sakura.ne.jp',
    'smtp_host' => 'your-initial-domain.sakura.ne.jp',
];
