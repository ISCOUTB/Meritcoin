<?php
// db/events.php - Eventos que escucha el plugin MeritCoin
defined('MOODLE_INTERNAL') || die();

$observers = [

    // ─── Completación de curso ───────────────────────────────────────────────
    [
        'eventname'   => '\core\event\course_completed',
        'callback'    => '\local_meritcoin\observer::course_completed',
        'includefile' => null,
        'internal'    => false,
        'priority'    => 200,
    ],

    // ─── Calificación registrada ─────────────────────────────────────────────
    [
        'eventname'   => '\core\event\user_graded',
        'callback'    => '\local_meritcoin\observer::user_graded',
        'includefile' => null,
        'internal'    => false,
        'priority'    => 200,
    ],
];
