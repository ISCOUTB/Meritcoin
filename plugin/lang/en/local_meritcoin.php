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

// ── Marketplace: student view ─────────────────────────────────────────────────
$string['marketplacetitle']          = 'Rewards Marketplace';
$string['marketplaceavailable']      = 'Available balance in this course';
$string['marketplaceempty']          = 'The teacher has not published any rewards for this course yet.';
$string['marketplaceretroacwarning'] = 'Your balance only reflects activity recorded since MeritCoin was installed. Courses or activities completed before installation did not generate tokens.';
$string['marketplaceredeembtn']      = 'Redeem';
$string['marketplaceredeemedbadge']  = 'Already redeemed';
$string['marketplacenotenoughbtn']   = 'Insufficient balance';
$string['marketplaceconfirm']        = 'Redeem "{name}" for {price} {symbol}? This action cannot be undone.';
$string['marketplaceredeemed']       = 'Reward redeemed successfully!';
$string['marketplacerewardnotfound'] = 'The reward does not exist or is no longer available.';
$string['marketplacealreadyredeemed']= 'You have already redeemed this reward.';
$string['marketplacenotenough']      = 'You do not have enough tokens in this course to redeem this reward.';

// ── Admin marketplace ─────────────────────────────────────────────────────────
$string['adminmarketplacetitle']  = 'MeritCoin — Marketplace Panel';
$string['adminrewardsactive']     = 'Active rewards';
$string['adminrewardsinactive']   = 'Inactive rewards';
$string['admintotalredemptions']  = 'Total redemptions';
$string['admintotalspent']        = 'Tokens spent';
$string['adminteacher']           = 'Teacher';
$string['admincolstudent']        = 'Student';
$string['admincoinsspent']        = 'Tokens spent';
$string['admintxhash']            = 'TX Hash';
$string['tabrewards']             = 'Rewards';
$string['tabredemptions']         = 'Redemption history';
$string['filterbycourse']         = 'Filter by course';
$string['allcourses']             = 'All courses';
$string['adminrewardsempty']      = 'No rewards have been created yet.';
$string['adminredemptionsempty']  = 'No redemptions recorded yet.';
$string['pluginsettings']         = 'Plugin settings';

$string['rule_scope_activity_type']     = 'Activity type';
$string['rule_mod_type']                = 'Module type';
$string['rule_select_mod_type']         = '-- Select type --';
$string['rule_min_grade']               = 'Minimum grade';
$string['rule_min_grade_placeholder']   = 'Leave empty for no minimum';
$string['rule_min_grade_help']          = 'If set, coins are only awarded when the student reaches this grade. Leave empty to always award coins.';
$string['error_invalid_grade']          = 'Must be a number';
$string['error_positive_grade']         = 'Must be 0 or greater';

$string['col_reevals']      = 'Reevals';
$string['col_reevals_hint'] = 'Number of times this activity has been graded';

$string['teacher_transactions_title'] = 'Course transactions';
$string['teacher_tab_earnings']       = 'Coins awarded';
$string['teacher_kpi_awarded']        = 'Coins awarded';
$string['teacher_filter_student']     = 'Filter by student';
$string['teacher_all_students']       = 'All students';
$string['teacher_clear_filter']       = 'Clear filter';
$string['teacher_no_earnings']        = 'No coins awarded yet in this course.';
$string['admin_tab_transactions']     = 'All transactions';

// Badge verification page
$string['badge_verify_title']        = 'Badge Verification — MeritCoin';
$string['badge_verify_authentic']    = 'Authentic Badge';
$string['badge_verify_not_authentic']= 'Badge not found';
$string['badge_verify_invalid_title']= 'Invalid Badge';
$string['badge_verify_no_hash']      = 'No verification code provided.';
$string['badge_verify_invalid']      = 'The verification code format is invalid.';
$string['badge_verify_not_found']    = 'No badge was found with this verification code.';
$string['badge_verify_student']      = 'Awarded to';
$string['badge_verify_course']       = 'Course';
$string['badge_verify_type']         = 'Type';
$string['badge_verify_issued_by']    = 'Issued by';
$string['badge_verify_issued_on']    = 'Issued on';
$string['badge_verify_coins']        = 'MRT at issue';
$string['badge_verify_help']         = 'If you believe this is an error, contact the institution that issued the badge.';

// ── Badge PDF certificate (badge_pdf.php) ─────────────────────────────────────
$string['badge_pdf_certificate_label'] = 'Badge Certificate';
$string['badge_pdf_awarded_to_label']  = 'This certifies that';
$string['badge_pdf_course']            = 'Course';
$string['badge_pdf_issued_by']         = 'Issued by';
$string['badge_pdf_issued_on']         = 'Issue date';
$string['badge_pdf_verified']          = 'Verified';
$string['badge_pdf_verify_at']         = 'Verify at';
$string['badge_pdf_institution']       = 'Tecnológica de Bolívar';

$string['badge_pdf_download'] = 'Download PDF';
$string['badge_copy_link']    = 'Copy link';
$string['badge_link_copied']  = 'Link copied!';

// ── Badge templates ───────────────────────────────────────────────────────────
$string['badge_templates_title']    = 'Badge Templates';
$string['template_new']             = 'New template';
$string['template_edit']            = 'Edit template';
$string['template_empty']           = 'No templates yet. Create one to start awarding badges.';
$string['template_created']         = 'Template created successfully.';
$string['template_updated']         = 'Template updated successfully.';
$string['template_deleted']         = 'Template deleted.';
$string['template_has_badges']      = 'Cannot delete: badges have already been issued from this template.';
$string['template_confirm_delete']  = 'Delete this template? This action cannot be undone.';
$string['template_issued']          = 'issued';
$string['template_name']            = 'Badge name';
$string['template_type']            = 'Badge type';
$string['template_description']     = 'Description';
$string['template_description_help'] = 'Description of the achievement this badge represents. Will appear on the PDF certificate.';
$string['template_criteria']        = 'Criteria';
$string['template_criteria_help']   = 'Explain what the student must do to earn this badge.';
$string['template_image_url']       = 'Image URL (optional)';
$string['template_scope']           = 'Scope';
$string['template_scope_help']      = 'Global: available for any course (admin only). Course: only for your course.';
$string['template_scope_global']    = 'Global (all courses)';
$string['template_scope_course']    = 'This course';
$string['badge_award_btn']          = 'Award badge';

// ── Award badge ───────────────────────────────────────────────────────────────
$string['award_badge_title']      = 'Award Badge';
$string['award_select_template']  = 'Badge template';
$string['award_select_students']  = 'Students';
$string['award_select_students_help'] = 'Hold Ctrl (or Cmd on Mac) to select multiple students at once.';
$string['award_notes']            = 'Internal note (optional)';
$string['award_btn']              = 'Award badge';
$string['award_success']          = '{$a} badge(s) awarded successfully.';
$string['award_none_issued']      = 'No badges were issued. Check your selection.';
$string['award_no_templates']     = 'No templates available. Please create one first.';
$string['award_no_students']      = 'No enrolled students with the required permissions.';

// ── Badges: verify & PDF (v0.4.0) ────────────────────────────────────────────
$string['badge_verify_title']       = 'Badge Verification';
$string['badge_verified']           = '✓ Verified Badge';
$string['badge_verify_invalid']     = 'Invalid badge';
$string['badge_verify_invalid_desc']= 'This verification link is not valid or the badge no longer exists.';
$string['badge_awarded_to']         = 'Awarded to';
$string['badge_issued_by']          = 'Issued by';
$string['badge_issuer_role']        = 'Course instructor';
$string['badge_description']        = 'Description';
$string['badge_criteria']           = 'Criteria';
$string['badge_hash']               = 'Verification hash';
$string['badge_copy_link']          = 'Copy link';
$string['badge_link_copied']        = 'Copied!';
$string['badge_pdf_download']       = 'Download certificate';
$string['badge_certificate_title']  = 'Badge Certificate';
$string['badge_certificate_of']     = 'Certificate of achievement';
$string['verifybadge']              = 'Verify badge';
$string['verify']                   = 'Verify';
$string['balancelocal']             = 'local estimate';

// ── Badges: award panel (v0.4.0) ──────────────────────────────────────────────
$string['badge_award_title']          = 'Award Badges';
$string['badge_award_new']            = 'Award a new badge';
$string['badge_award_student']        = 'Student';
$string['badge_award_select_student'] = 'Select a student';
$string['badge_award_type']           = 'Badge type';
$string['badge_award_select_type']    = 'Select a badge type';
$string['badge_award_btn']            = 'Award';
$string['badge_awarded_ok']           = 'Badge awarded successfully.';
$string['badge_already_has']          = 'This student already has this badge in the course.';
$string['badge_revoked_ok']           = 'Badge revoked.';
$string['badge_revoke_btn']           = 'Revoke';
$string['badge_revoke_confirm']       = 'Revoke this badge? This action cannot be undone.';
$string['badge_awarded_list']         = 'Badges awarded in this course';
$string['badge_none_awarded_yet']     = 'No badges awarded yet in this course.';
$string['badge_no_types_warning']     = 'No badge types are configured. Go to the admin panel to create badge types first.';
$string['badge_col_badge']            = 'Badge';
$string['badge_col_student']          = 'Student';
$string['badge_col_verify']           = 'Verify';

$string['badge_types_menu'] = 'MeritCoin – Badge types';

// ── Badge types admin (badge_types.php) ───────────────────────────────────────
$string['badge_types_title']           = 'MeritCoin – Badge types';
$string['badge_types_desc']            = 'Create and manage the types of badges that teachers can award to students.';
$string['badge_types_list']            = 'Configured badge types';
$string['badge_types_empty']           = 'No badge types configured yet. Create one to get started.';
$string['badge_type_new']              = 'New badge type';
$string['badge_type_edit']             = 'Edit badge type';
$string['badge_type_name']             = 'Name';
$string['badge_type_shortname']        = 'Shortname';
$string['badge_type_shortname_help']   = 'Unique identifier, letters and numbers only. Cannot be changed for system types.';
$string['badge_type_shortname_exists'] = 'A badge type with that shortname already exists.';
$string['badge_type_description']      = 'Description';
$string['badge_type_criteria']         = 'Award criteria';
$string['badge_type_color']            = 'Color';
$string['badge_type_icon']             = 'Icon';
$string['badge_type_image_url']        = 'Image URL';
$string['badge_type_sortorder']        = 'Sort order';
$string['badge_type_enabled']          = 'Enabled';
$string['badge_type_created']          = 'Badge type created successfully.';
$string['badge_type_updated']          = 'Badge type updated successfully.';
$string['badge_type_deleted']          = 'Badge type deleted.';
$string['badge_type_toggled']          = 'Badge type status updated.';
$string['badge_type_delete_confirm']   = 'Delete this badge type? This action cannot be undone.';$string['task_process_redemptions'] = 'Process pending marketplace redemptions';
$string['student_course_limit']          = 'Student MRT limit per course';
$string['student_course_limit_desc']     = 'Maximum MRT tokens a student can earn per course during the whole semester.';
$string['student_course_limit_exceeded'] = 'This student has reached the MRT limit for this course ({$a->used}/{$a->limit}).';
