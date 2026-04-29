<?php
// This file is part of Moodle - [http://moodle.org/](http://moodle.org/)
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
$string['statussent']              = 'Sent';
$string['statuspending']           = 'Pending';
$string['statusfailed']            = 'Failed';
$string['statusunknown']           = 'Unknown';
$string['queue_status_pending']        = 'Pending';
$string['queue_status_pending_wallet'] = 'Waiting for wallet';
$string['queue_status_sent']           = 'Sent';
$string['queue_status_failed']         = 'Failed';

// ── Settings ──────────────────────────────────────────────────────────────────
$string['settings_enabled']           = 'Enable plugin';
$string['settings_enabled_desc']      = 'When disabled, no events will be queued or sent.';
$string['settings_backend_url']       = 'Backend URL';
$string['settings_backend_url_desc']  = 'Base URL of the FastAPI backend, e.g. https://api.example.com';
$string['settings_api_key']           = 'API Key';
$string['settings_api_key_desc']      = 'Secret key sent in every request to the backend.';
$string['settings_wallet_field']      = 'Wallet field';
$string['settings_wallet_field_desc'] = 'Shortname of the custom user profile field that stores the Ethereum wallet address (e.g. wallet).';
$string['settingshmacsecret']         = 'HMAC Secret';

// ── Reward rules (v0.2.0) ─────────────────────────────────────────────────────
$string['settingsrules']            = 'Reward Rules';
$string['settingsrulesdesc']        = 'Configure how many coins each course or activity awards.';
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
$string['courseconfigcontractdesc']     = 'ERC-20 contract specific to this course (optional).';

// ── Task ──────────────────────────────────────────────────────────────────────
$string['task_send_events']  = 'Send queued MeritCoin events to backend';

// ── Errors ────────────────────────────────────────────────────────────────────
$string['no_wallet']        = 'Student does not have a wallet in field \'{$a}\'.';
$string['invalidwallet']    = 'Invalid Ethereum wallet format for user {$a}.';
$string['gradebelowmin']    = 'Grade {$a} is below the minimum required to earn coins.';

// ── Manage rules page ─────────────────────────────────────────────────────────
$string['manage_rules']          = 'MeritCoin – Coin rules';
$string['manage_rules_desc']     = 'Configure how many coins students earn per activity or for completing this course.';
$string['rules_table_scope']     = 'Scope';
$string['rules_table_activity']  = 'Activity';
$string['rules_table_coins']     = 'Coins';
$string['rules_table_symbol']    = 'Symbol';
$string['rules_table_status']    = 'Status';
$string['rules_table_actions']   = 'Actions';
$string['rule_enabled']          = 'Active';
$string['rule_disabled']         = 'Inactive';
$string['rule_enable_action']    = 'Enable';
$string['rule_disable_action']   = 'Disable';
$string['rule_delete_action']    = 'Delete';
$string['rule_delete_confirm']   = 'Are you sure you want to delete this rule?';
$string['norules']               = 'No rules configured yet. Create one to start awarding coins.';

// ── Rule form (editrule.php + rule_form.php) ──────────────────────────────────
$string['newrule']                = 'New coin rule';
$string['editrule']               = 'Edit coin rule';
$string['rule_created']           = 'Rule created successfully.';
$string['rule_updated']           = 'Rule updated successfully.';
$string['rule_deleted']           = 'Rule deleted.';
$string['rule_toggled']           = 'Rule status updated.';
$string['rule_duplicate_updated'] = 'A rule for this activity already existed; it has been updated instead.';
$string['rule_scope']             = 'Rule scope';
$string['rule_scope_course']      = 'Entire course (default for all graded activities)';
$string['rule_scope_activity']    = 'Specific activity';
$string['activity_name']          = 'Activity display name';
$string['coins_amount']           = 'Coins to award';
$string['coin_symbol']            = 'Coin symbol (e.g. MRT)';
$string['rule_enabled_desc']      = 'Active';
$string['selectactivity']         = '— Select an activity —';
$string['error_positive_coins']   = 'Coins amount must be greater than zero.';
$string['activity_help']          = 'Select the specific activity for which this rule applies. If you choose "Entire course", the rule applies to all graded activities without their own rule.';

// ── Marketplace: rewards (teacher) ────────────────────────────────────────────
$string['rewardstitle']         = 'Marketplace Rewards';
$string['rewardnew']            = 'New reward';
$string['rewardname']           = 'Name';
$string['rewardnameph']         = 'E.g.: Quiz exemption';
$string['rewarddesc']           = 'Description';
$string['rewarddescph']         = 'E.g.: Exempts you from the week 3 quiz';
$string['rewardprice']          = 'Price';
$string['rewardcreatebtn']      = 'Create reward';
$string['rewardslist']          = 'Created rewards';
$string['rewardsempty']         = 'You have not created any rewards for this course yet.';
$string['rewardactive']         = 'Active';
$string['rewardinactive']       = 'Inactive';
$string['rewardactivate']       = 'Activate';
$string['rewarddeactivate']     = 'Deactivate';
$string['rewarddelete']         = 'Delete';
$string['rewardconfirmdelete']  = 'Delete this reward? This action cannot be undone.';
$string['rewardredemptions']    = 'Redemptions';
$string['rewardactions']        = 'Actions';
$string['rewardcreated']        = 'Reward created successfully.';
$string['rewardtoggled']        = 'Reward status updated.';
$string['rewarddeleted']        = 'Reward deleted.';
$string['rewardinvaliddata']    = 'Invalid data. Please check the name and price.';
$string['rewardhasredemptions'] = 'Cannot delete: students have already redeemed this reward.';
$string['backtocourse']         = 'Back to course';