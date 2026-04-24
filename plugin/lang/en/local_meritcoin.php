<?php
// This file is part of Moodle - http://moodle.org/
//
// @package local_meritcoin
// @copyright 2026 Universidad Tecnológica de Bolívar
// @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

// ── General ───────────────────────────────────────────────────────────────────
$string['pluginname']       = 'MeritCoin';
$string['mymeritcoin']      = 'My MeritCoin';

// ── Dashboard ─────────────────────────────────────────────────────────────────
$string['dashboardtitle']   = 'My MeritCoin Dashboard';
$string['dashboardheading'] = 'MeritCoin - My Achievements & Rewards';

// ── Hero ──────────────────────────────────────────────────────────────────────
$string['mrtbalance']       = 'MRT Balance';
$string['walletaddress']    = 'Wallet Address';
$string['nowallet']         = 'No wallet registered';
$string['copywallet']       = 'Copy address';

// ── Alerts ────────────────────────────────────────────────────────────────────
$string['backendunavailable'] = 'The blockchain service is currently unavailable. Showing local data.';
$string['nowalletmsg']        = 'You do not have an Ethereum wallet registered. To receive MRT tokens, add your address in your';
$string['editprofile']        = 'user profile';

// ── Stats ─────────────────────────────────────────────────────────────────────
$string['statcompletions']  = 'Courses completed';
$string['statavggrade']     = 'Average grade';
$string['statsent']         = 'Events sent';
$string['statpending']      = 'Pending';
$string['stattotalcoins']   = 'Total coins earned';

// ── Badges ────────────────────────────────────────────────────────────────────
$string['badgessection']        = 'My Badges';
$string['badgesbackendneeded']  = 'Badges will appear once the blockchain service is active.';
$string['nobadgesyet']          = 'No badges yet';
$string['nobadgeshint']         = 'Complete courses and earn good grades to receive MeritCoin badges.';

// ── Activity history ──────────────────────────────────────────────────────────
$string['eventshistory']    = 'Activity History';
$string['noeventsyet']      = 'No activity recorded yet.';
$string['showinglast20']    = 'Showing last 20 events. See all in the admin panel.';

// ── Table columns ─────────────────────────────────────────────────────────────
$string['coltype']          = 'Type';
$string['colcourse']        = 'Course';
$string['colactivity']      = 'Activity';
$string['colgrade']         = 'Grade';
$string['colcoins']         = 'Coins';
$string['colstatus']        = 'Status';
$string['coldate']          = 'Date';
$string['courseid']         = 'Course ID';

// ── Event types ───────────────────────────────────────────────────────────────
$string['typecompletion']   = 'Completion';
$string['typegrade']        = 'Grade';

// ── Statuses ──────────────────────────────────────────────────────────────────
$string['statussent']       = 'Sent';
$string['statuspending']    = 'Pending';
$string['statusfailed']     = 'Failed';
$string['statusunknown']    = 'Unknown';

// ── Settings ──────────────────────────────────────────────────────────────────
$string['settingsenabled']      = 'Enable plugin';
$string['settingsbackendurl']   = 'Backend URL';
$string['settingshmacsecret']   = 'HMAC Secret';
$string['settingswalletfield']  = 'Wallet field';

// ── Reward rules (v0.2.0) ─────────────────────────────────────────────────────
$string['settingsrules']            = 'Reward Rules';
$string['settingsrulesdesc']        = 'Configure how many coins each course or activity awards. If no rule is set, the default formula is used: grade / 10 coins, or 50 coins for course completion.';
$string['rulescourseid']            = 'Course ID';
$string['rulesactivity']            = 'Activity (optional)';
$string['rulesactivitydesc']        = 'Leave empty to apply this rule to the entire course.';
$string['rulescoinsfixed']          = 'Fixed coins';
$string['rulescoinsfixeddesc']      = 'Award this many coins regardless of grade (e.g. 10).';
$string['rulescoinspct']            = 'Grade multiplier';
$string['rulescoinspctdesc']        = 'Multiply grade by this factor (e.g. 0.5 → grade 85 = 42.5 coins).';
$string['rulesmingrade']            = 'Minimum grade';
$string['rulesmingratedesc']        = 'Student must reach this grade to earn coins (default: 0).';
$string['norulefound']              = 'No rule found. Using default formula.';

// ── Course coin config (v0.2.0) ───────────────────────────────────────────────
$string['settingscourseconfig']         = 'Course Coin Configuration';
$string['settingscourseconfigdesc']     = 'Assign a custom coin name, symbol, and smart contract address per course.';
$string['courseconfigcoinname']         = 'Coin name';
$string['courseconfigcoinsymbol']       = 'Coin symbol';
$string['courseconfigcontract']         = 'Contract address';
$string['courseconfigcontractdesc']     = 'ERC-20 contract specific to this course (optional). Leave empty to use the global MRT contract.';

// ── Task ──────────────────────────────────────────────────────────────────────
$string['task:sendevents']  = 'MeritCoin: Send pending events to blockchain backend';

// ── Errors ────────────────────────────────────────────────────────────────────
$string['no_wallet']        = 'Student does not have a wallet in field \'{$a}\'.';
$string['invalidwallet']    = 'Invalid Ethereum wallet format for user {$a}.';
$string['gradebelowmin']    = 'Grade {$a} is below the minimum required to earn coins.';