<?php
// ====== Royal Hold'em configuration ======
// Fill these in with the MySQL details from your hosting control panel (cPanel -> MySQL Databases).

return [
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DB_NAME',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASSWORD',

    // How many seconds a player can go without polling before they are treated as disconnected
    'presence_timeout' => 20,

    // ICE servers for WebRTC. STUN is enough for most home connections; the TURN relay below (free public
    // Open Relay by Metered) helps players behind strict firewalls/mobile carriers see each other.
    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
        ['urls' => 'turn:openrelay.metered.ca:80', 'username' => 'openrelayproject', 'credential' => 'openrelayproject'],
        ['urls' => 'turn:openrelay.metered.ca:443', 'username' => 'openrelayproject', 'credential' => 'openrelayproject'],
        ['urls' => 'turn:openrelay.metered.ca:443?transport=tcp', 'username' => 'openrelayproject', 'credential' => 'openrelayproject'],
        // Your own TURN server (recommended for a busy site):
        // ['urls' => 'turn:turn.example.com:3478', 'username' => 'user', 'credential' => 'pass'],
    ],
];
