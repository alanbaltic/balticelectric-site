<?php
/**
 * Plugin Name: Baltic QMS Portal
 * Description: Staff-only QMS portal for recording MCS evidence (projects + records) with DOC export and print-to-PDF.
 * Version: 2.2.8
 * Author: Baltic Electric
 */

if (!defined('ABSPATH')) { exit; }

class BE_QMS_Portal {
  const VERSION = '2.2.8';
  const CPT_RECORD = 'be_qms_record';
  const CPT_PROJECT = 'be_qms_install'; // keep CPT key for backwards compatibility
  const TAX_RECORD_TYPE = 'be_qms_record_type';
  const META_PROJECT_LINK = '_be_qms_install_id';

  const CPT_EMPLOYEE = 'be_qms_employee';
  const CPT_TRAINING = 'be_qms_training';
  const META_EMPLOYEE_LINK = '_be_qms_employee_id';

  public static function init() {
    add_action('init', [__CLASS__, 'register_types']);
    add_action('init', [__CLASS__, 'maybe_handle_exports']);

    add_shortcode('be_qms_portal', [__CLASS__, 'shortcode_portal']);
    // Backwards compatible shortcodes
    add_shortcode('bqms_dashboard', [__CLASS__, 'shortcode_portal']);
    add_shortcode('bqms_portal', [__CLASS__, 'shortcode_portal']);

    add_action('admin_post_be_qms_save_record', [__CLASS__, 'handle_save_record']);
    add_action('admin_post_be_qms_save_project', [__CLASS__, 'handle_save_project']);
    add_action('admin_post_be_qms_save_project_checklist', [__CLASS__, 'handle_save_project_checklist']);
    add_action('admin_post_be_qms_save_employee', [__CLASS__, 'handle_save_employee']);
    add_action('admin_post_be_qms_save_training', [__CLASS__, 'handle_save_training']);
    add_action('admin_post_be_qms_save_r03_upload', [__CLASS__, 'handle_save_r03_upload']);
    add_action('admin_post_be_qms_remove_r03_upload', [__CLASS__, 'handle_remove_r03_upload']);
    add_action('admin_post_be_qms_save_profile', [__CLASS__, 'handle_save_profile']);
    add_action('admin_post_be_qms_delete', [__CLASS__, 'handle_delete']);

    // Light, scoped styles + datepicker
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
  }

  public static function on_activate() {
    self::register_types();
    flush_rewrite_rules();

    // Create /qms-portal if it doesn't exist.
    $slug = 'qms-portal';
    $existing = get_page_by_path($slug);
    if (!$existing) {
      wp_insert_post([
        'post_title'   => 'QMS Portal',
        'post_name'    => $slug,
        'post_status'  => 'private',
        'post_type'    => 'page',
        'post_content' => '[be_qms_portal]',
      ]);
    }
  }

  private static function is_portal_page() {
    if (!is_singular()) return false;
    global $post;
    if (!$post) return false;
    $content = (string) $post->post_content;
    return (strpos($content, '[be_qms_portal') !== false) || (strpos($content, '[bqms_portal') !== false) || (strpos($content, '[bqms_dashboard') !== false);
  }

  private static function normalize_date_input($value) {
    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }

    $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $format) {
      $parsed = DateTime::createFromFormat($format, $value);
      if ($parsed instanceof DateTime) {
        return $parsed->format('Y-m-d');
      }
    }

    return sanitize_text_field($value);
  }

  private static function format_date_for_display($value) {
    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $format) {
      $parsed = DateTime::createFromFormat($format, $value);
      if ($parsed instanceof DateTime) {
        return $parsed->format('d/m/Y');
      }
    }

    return $value;
  }

  public static function enqueue_assets() {
    if (!self::is_portal_page()) return;

    $css = <<<'CSS'
/* Baltic QMS Portal — Scoped to .be-qms-wrap */
.be-qms-wrap{
  --qms-bg:#020617;              /* slate-950 */
  --qms-panel:rgba(15,23,42,.60);/* slate-900/60 */
  --qms-panel2:rgba(2,6,23,.55);
  --qms-border:#1e293b;          /* slate-800 */
  --qms-border2:#334155;         /* slate-700 */
  --qms-text:#e2e8f0;            /* slate-200 */
  --qms-muted:#94a3b8;           /* slate-400 */
  --qms-emerald:#34d399;         /* emerald-400 */
  --qms-emerald2:#6ee7b7;        /* emerald-300 */
  --qms-amber:#fbbf24;           /* amber-400 */
  --qms-danger:#f87171;          /* red-400 */

  max-width:1200px;
  margin:0 auto;
  padding:18px 14px;
  color:var(--qms-text);
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
}
.be-qms-wrap *{ box-sizing:border-box; }

.be-qms-card{
  background:var(--qms-panel);
  border:1px solid var(--qms-border);
  border-radius:18px;
  padding:16px;
  margin:14px 0;
  box-shadow:0 18px 60px rgba(16,185,129,.10);
  backdrop-filter: blur(10px);
}

.be-qms-title{ font-size:26px; font-weight:800; letter-spacing:-.02em; margin:0 0 10px 0; color:#f8fafc; }
.be-qms-muted{ color:var(--qms-muted); font-size:13px; }

.be-qms-nav{ display:flex; flex-wrap:wrap; gap:10px; margin:10px 0 16px 0; }
.be-qms-tab{
  display:inline-block;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid var(--qms-border);
  background:rgba(2,6,23,.35);
  color:var(--qms-text);
  text-decoration:none;
  font-weight:700;
  font-size:13px;
  transition: background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.be-qms-tab:hover{ background:rgba(15,23,42,.65); border-color:var(--qms-border2); transform: translateY(-1px); }
.be-qms-tab.is-active{ background:rgba(16,185,129,.14); border-color:rgba(52,211,153,.55); box-shadow:0 0 0 4px rgba(16,185,129,.12); }

.be-qms-row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

.be-qms-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid transparent;
  background:var(--qms-emerald);
  color:#020617 !important;
  text-decoration:none;
  font-weight:800;
  font-size:13px;
  cursor:pointer;
  line-height:1;
  box-shadow:0 14px 40px rgba(16,185,129,.16);
  transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.be-qms-btn:hover{ background:var(--qms-emerald2); transform: translateY(-1px); }
.be-qms-btn:active{ transform: translateY(0); }

.be-qms-btn-secondary{ background:rgba(2,6,23,.35); border-color:var(--qms-border); color:var(--qms-text) !important; box-shadow:none; }
.be-qms-btn-secondary:hover{ background:rgba(15,23,42,.65); border-color:var(--qms-border2); }

.be-qms-btn-danger{ background:rgba(248,113,113,.14); border-color:rgba(248,113,113,.45); color:#fecaca !important; box-shadow:none; }
.be-qms-btn-danger:hover{ background:rgba(248,113,113,.20); border-color:rgba(248,113,113,.65); }

.be-qms-btn:focus-visible, .be-qms-tab:focus-visible{ outline:none; box-shadow:0 0 0 4px rgba(251,191,36,.18); }

.be-qms-link{ color:var(--qms-emerald2); text-decoration:none; font-weight:700; }
.be-qms-link:hover{ text-decoration:underline; }

.be-qms-table{ width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
.be-qms-table th, .be-qms-table td{ padding:10px 10px; border-top:1px solid rgba(30,41,59,.75); vertical-align:top; }
.be-qms-table th{ color:#f8fafc; font-weight:800; font-size:12px; letter-spacing:.02em; text-transform:uppercase; }
.be-qms-table tr:hover td{ background:rgba(15,23,42,.35); }

.be-qms-input, .be-qms-textarea, .be-qms-select{
  width:100%;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--qms-border2);
  background:var(--qms-panel2);
  color:var(--qms-text);
  outline:none;
  transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.be-qms-input::placeholder, .be-qms-textarea::placeholder{ color: rgba(148,163,184,.60); }
.be-qms-input:focus, .be-qms-textarea:focus, .be-qms-select:focus{ border-color:rgba(52,211,153,.70); box-shadow:0 0 0 4px rgba(16,185,129,.18); background:rgba(2,6,23,.70); }
.be-qms-textarea{ min-height:110px; resize:vertical; }
.be-qms-date{
  background-image:url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='none'%20stroke='white'%20stroke-width='2'%20stroke-linecap='round'%20stroke-linejoin='round'%3E%3Crect%20x='3'%20y='4'%20width='18'%20height='18'%20rx='2'%20ry='2'/%3E%3Cline%20x1='16'%20y1='2'%20x2='16'%20y2='6'/%3E%3Cline%20x1='8'%20y1='2'%20x2='8'%20y2='6'/%3E%3Cline%20x1='3'%20y1='10'%20x2='21'%20y2='10'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 12px center;
  background-size:16px 16px;
  padding-right:38px;
}

.be-qms-grid{ display:grid; grid-template-columns:repeat(12,1fr); gap:10px; }
.be-qms-col-6{ grid-column:span 6; }
.be-qms-col-12{ grid-column:span 12; }
@media(max-width:900px){ .be-qms-col-6{ grid-column:span 12; } }

/* Records layout */
.be-qms-split{ display:grid; grid-template-columns:260px 1fr; gap:14px; }
@media(max-width:900px){ .be-qms-split{ grid-template-columns:1fr; } }

.be-qms-side{
  background:rgba(2,6,23,.35);
  border:1px solid var(--qms-border);
  border-radius:16px;
  padding:10px;
}
.be-qms-side a{
  display:block;
  padding:10px 10px;
  border-radius:12px;
  text-decoration:none;
  color:var(--qms-text);
  border:1px solid transparent;
  font-weight:700;
}
.be-qms-side a:hover{ background:rgba(15,23,42,.55); border-color:var(--qms-border2); }
.be-qms-side a.is-active{ background:rgba(16,185,129,.14); border-color:rgba(52,211,153,.55); }

/* jQuery UI datepicker (minimal, readable on dark background) */
.ui-datepicker{ font-family:inherit; font-size:13px; background:#0b1220; border:1px solid #334155; color:#e2e8f0; padding:10px; border-radius:12px; }
.ui-datepicker a{ color:#e2e8f0; }
.ui-datepicker .ui-datepicker-header{ background:#0f172a; border:1px solid #334155; border-radius:10px; padding:6px; }
.ui-datepicker .ui-state-default{ background:#111827; border:1px solid #334155; color:#e2e8f0; border-radius:8px; text-align:center; }
.ui-datepicker .ui-state-hover{ background:#0f172a; border-color:#475569; }
.ui-datepicker .ui-state-active{ background:#10b981; border-color:#10b981; color:#020617; }
CSS;

    wp_register_style('be-qms-inline', false);
    wp_enqueue_style('be-qms-inline');
    wp_add_inline_style('be-qms-inline', $css);

    // Scripts (datepicker)
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-datepicker');
    $js = <<<'JS'
jQuery(function($){
  $('.be-qms-date').each(function(){
    try{
      $(this).datepicker({
        dateFormat: 'dd/mm/yy',
        showButtonPanel: true,
        currentText: 'Today',
        closeText: 'Close'
      });
    }catch(e){}
  });

  function toggleTemplateName(){
    var $checkbox = $('[name="r03_save_template"]');
    var $field = $('[name="r03_template_name"]');
    if (!$checkbox.length || !$field.length) return;
    if ($checkbox.is(':checked')) {
      $field.prop('disabled', false);
    } else {
      $field.prop('disabled', true);
    }
  }

  toggleTemplateName();
  $(document).on('change', '[name="r03_save_template"]', toggleTemplateName);

  function toggleProjectSubcontractor(){
    var $selector = $('[name="project_has_subcontractor"]');
    var $wrapper = $('[data-subcontractor-field]');
    var $subSelect = $('[name="project_subcontractor_id"]');
    if (!$selector.length || !$wrapper.length) return;
    if ($selector.val() === 'yes') {
      $wrapper.show();
      $subSelect.prop('disabled', false);
    } else {
      $wrapper.hide();
      $subSelect.prop('disabled', true);
    }
  }

  toggleProjectSubcontractor();
  $(document).on('change', '[name="project_has_subcontractor"]', toggleProjectSubcontractor);
});
JS;
    wp_add_inline_script('jquery-ui-datepicker', $js);
  }

  private static function project_handover_items() {
    return [
      'design_report' => 'Design Report',
      'schematic' => 'Schematic',
      'dno_notification' => 'DNO Notification',
      'building_control_notification' => 'Building Control Notification',
      'pv_array_test_report' => 'PV Array Test Report',
      'eic' => 'EIC',
    ];
  }

  private static function get_record_type_slug($record_id) {
    $terms = get_the_terms($record_id, self::TAX_RECORD_TYPE);
    if ($terms && !is_wp_error($terms)) {
      return $terms[0]->slug;
    }
    return '';
  }

  private static function record_type_definitions() {
    // Exact order requested (R01 - R11, skipping R10 as per screenshot)
    return [
      'r01_contracts'            => 'R01 Contracts Folder',
      'r02_capa'                 => 'R02 Corrective & Preventative Action Record',
      'r03_purchase_order'       => 'R03 Purchase Order',
      'r04_tool_calibration'     => 'R04 Tool Calibration',
      'r05_internal_review'      => 'R05 Internal Review Record',
      'r06_customer_complaints'  => 'R06 Customer Complaints',
      'r07_training_matrix'      => 'R07 Personal Skills & Training Matrix',
      'r08_approved_suppliers'   => 'R08 Approved Suppliers',
      'r09_approved_subcontract' => 'R09 Approved Subcontractors',
      'r11_company_documents'    => 'R11 Company Documents',
    ];
  }

  public static function register_types() {
    // Records
    register_post_type(self::CPT_RECORD, [
      'label' => 'QMS Records',
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-clipboard',
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    register_taxonomy(self::TAX_RECORD_TYPE, [self::CPT_RECORD], [
      'label' => 'Record Types',
      'public' => false,
      'show_ui' => true,
      'hierarchical' => false,
    ]);

    // Projects (kept as CPT key be_qms_install for backwards compat)
    register_post_type(self::CPT_PROJECT, [
      'labels' => [
        'name' => 'QMS Projects',
        'singular_name' => 'QMS Project',
        'add_new' => 'Add New Project',
        'add_new_item' => 'Add New Project',
        'edit_item' => 'Edit Project',
        'new_item' => 'New Project',
        'view_item' => 'View Project',
        'search_items' => 'Search Projects',
      ],
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-portfolio',
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    // Employees (used for R07 Training Matrix)
    register_post_type(self::CPT_EMPLOYEE, [
      'labels' => [
        'name' => 'QMS Employees',
        'singular_name' => 'QMS Employee',
        'add_new' => 'Add Employee',
        'add_new_item' => 'Add New Employee',
        'edit_item' => 'Edit Employee',
        'new_item' => 'New Employee',
        'view_item' => 'View Employee',
        'search_items' => 'Search Employees',
      ],
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-groups',
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    // Training records (child entries linked to an employee)
    register_post_type(self::CPT_TRAINING, [
      'labels' => [
        'name' => 'QMS Training Records',
        'singular_name' => 'QMS Training Record',
      ],
      'public' => false,
      'show_ui' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    // Ensure record-type terms exist
    foreach (self::record_type_definitions() as $slug => $name) {
      if (!term_exists($slug, self::TAX_RECORD_TYPE)) {
        wp_insert_term($name, self::TAX_RECORD_TYPE, ['slug' => $slug]);
      } else {
        $term = get_term_by('slug', $slug, self::TAX_RECORD_TYPE);
        if ($term && !is_wp_error($term) && $term->name !== $name) {
          wp_update_term((int)$term->term_id, self::TAX_RECORD_TYPE, ['name' => $name]);
        }
      }
    }

    // Soft migration: map old slugs to new slugs if old ones exist (keeps assignments)
    $legacy = [
      'internal_review'  => 'r05_internal_review',
      'contract_review'  => 'r01_contracts',
      'goods_in'         => 'r03_purchase_order',
      'tools'            => 'r04_tool_calibration',
      'training'         => 'r07_training_matrix',
      'complaints'       => 'r06_customer_complaints',
      'capa'             => 'r02_capa',
      'install_evidence' => 'r11_company_documents',
    ];
    foreach ($legacy as $old => $new) {
      $old_term = get_term_by('slug', $old, self::TAX_RECORD_TYPE);
      if ($old_term && !is_wp_error($old_term)) {
        $new_term = get_term_by('slug', $new, self::TAX_RECORD_TYPE);
        if (!$new_term || is_wp_error($new_term)) {
          $defs = self::record_type_definitions();
          wp_update_term((int)$old_term->term_id, self::TAX_RECORD_TYPE, [
            'slug' => $new,
            'name' => $defs[$new] ?? $old_term->name,
          ]);
        }
      }
    }
  }

  private static function require_staff() {
    if (!is_user_logged_in()) {
      auth_redirect();
      exit;
    }
    if (!current_user_can('edit_posts')) {
      wp_die('You do not have permission to access the QMS Portal.');
    }
  }

  private static function get_portal_action() {
    if (isset($_GET['be_action'])) return sanitize_key($_GET['be_action']);
    if (isset($_GET['action'])) return sanitize_key($_GET['action']);
    return '';
  }

  private static function normalize_view($view) {
    $view = sanitize_key((string)$view);
    // Backwards compatible alias
    if ($view === 'installations') return 'projects';
    return $view;
  }

  public static function shortcode_portal($atts = []) {
    self::require_staff();

    $view = self::normalize_view($_GET['view'] ?? 'records');

    // Tab order to match your screenshot
    $views = [
      'projects'    => 'Projects',
      'records'     => 'Records',
      'templates'   => 'Templates',
      'references'  => 'References',
      'assessment'  => 'How to pass assessment',
    ];
    if (!isset($views[$view])) $view = 'records';

    ob_start();
    echo '<div class="be-qms-wrap">';
    echo '<div class="be-qms-card">';
    echo '<div class="be-qms-title">Baltic Electric – QMS Portal</div>';
    echo '<div class="be-qms-muted">Staff-only. Record your MCS evidence here and export when needed.</div>';

    echo '<div class="be-qms-nav">';
    foreach ($views as $k => $label) {
      $cls = 'be-qms-tab' . ($k === $view ? ' is-active' : '');
      $url = esc_url(add_query_arg(['view' => $k], self::portal_url()));
      echo '<a class="' . esc_attr($cls) . '" href="' . $url . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';

    if ($view === 'records') {
      self::render_records();
    } elseif ($view === 'projects') {
      self::render_projects();
    } elseif ($view === 'templates') {
      self::render_templates();
    } elseif ($view === 'references') {
      self::render_references();
    } else {
      self::render_assessment();
    }

    echo '</div></div>';
    return ob_get_clean();
  }

  private static function render_templates() {
    echo '<div class="be-qms-card-inner">';
    echo '<h3>Templates (what you export / print)</h3>';
    echo '<p class="be-qms-muted">Create records in the portal, then export DOC or print to PDF for your assessor or your own filing.</p>';
    echo '<ul>';
    echo '<li><strong>R05 Internal Review Record</strong> – minutes + actions.</li>';
    echo '<li><strong>R01 Contracts Folder</strong> – contract review / scope / customer acceptance / variations.</li>';
    echo '<li><strong>R03 Purchase Order</strong> – goods-in checks / materials traceability where needed.</li>';
    echo '<li><strong>R04 Tool Calibration</strong> – tester/tool register + due dates.</li>';
    echo '<li><strong>R07 Personal Skills & Training Matrix</strong> – staff training, renewals, certs.</li>';
    echo '<li><strong>R06 Customer Complaints</strong> – log + outcome + any CAPA.</li>';
    echo '<li><strong>R02 CAPA</strong> – corrective/preventative actions (can be linked to projects).</li>';
    echo '</ul>';
    echo '</div>';
  }

  private static function render_references() {
    $manual_docx = esc_url(add_query_arg(['be_qms_ref'=>'manual_docx'], self::portal_url()));
    $manual_pdf  = esc_url(add_query_arg(['be_qms_ref'=>'manual_pdf'], self::portal_url()));
    $external_docs = [
      [
        'title' => 'NAPIT Assessment Checklist (v3)',
        'url' => 'https://napitweb.blob.core.windows.net/member-downloads/NAPIT-Assessment-Checklist-v3.pdf',
        'type' => 'External',
      ],
      [
        'title' => 'MCS-001-01',
        'url' => 'https://mcscertified.com/wp-content/uploads/2024/11/MCS-001-1-Issue-4.2_Final.pdf',
        'type' => 'External',
      ],
      [
        'title' => 'MCS-001-02',
        'url' => 'https://mcscertified.com/wp-content/uploads/2025/01/MCS-001-2-Issue-4.2_Final.pdf',
        'type' => 'External',
      ],
      [
        'title' => 'MIS 3002 (Solar PV)',
        'url' => 'https://mcscertified.com/wp-content/uploads/2025/02/MIS-3002_Solar-PV-Systems-V5.0-Final-for-publication.pdf',
        'type' => 'External',
      ],
      [
        'title' => 'MIS 3012 (Battery)',
        'url' => 'https://mcscertified.com/wp-content/uploads/2025/02/MIS-3012_Battery-Storage-Systems-V1.0.pdf',
        'type' => 'External',
      ],
    ];
    $accessed_docs = [
      [
        'title' => 'Planning Portal Approved Documents',
        'url' => 'http://www.planningportal.gov.uk/buildingregulations/approveddocuments/',
      ],
    ];

    $profile = get_option('be_qms_profile', []);
    if (!is_array($profile)) $profile = [];
    $defaults = [
      'company_name' => 'Baltic Electric Ltd',
      'responsible_person' => 'Alan Baltic',
      'address' => 'London, UK',
      'phone' => '',
      'email' => '',
      'company_reg' => '',
      'mcs_reg' => '',
      'consumer_code' => '',
    ];
    $profile = array_merge($defaults, $profile);

    echo '<div class="be-qms-card-inner">';
    echo '<h3>References & QMS framework</h3>';
    echo '<p class="be-qms-muted">Keep your profile up to date, then store evidence as Records (R01-R11) and link items to Projects where relevant.</p>';

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6">';
    echo '<h4 style="margin-top:0">Documented Procedure Manual</h4>';
    echo '<p class="be-qms-muted">Based on the template you uploaded, with Appendix A & C prefilled for Baltic Electric (placeholders where details are unknown).</p>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$manual_docx.'">Download DOCX</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$manual_pdf.'" target="_blank">Open PDF</a>'
      .'</div>';
    echo '</div>';

    echo '<div class="be-qms-col-6">';
    echo '<h4 style="margin-top:0">Standards (official sources)</h4>';
    echo '<ul>';
    foreach ($external_docs as $doc) {
      echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($doc['url']).'">'.esc_html($doc['title']).'</a></li>';
    }
    echo '</ul>';
    echo '<div class="be-qms-muted">Use these as the starting entries for your external document register tab.</div>';
    echo '</div>';

    echo '</div>';

    echo '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(30,41,59,.75)">';
    echo '<h4>Document &amp; Data Control</h4>';
    echo '<p class="be-qms-muted">Track external documents and the internal documents/data you control. Use the template to maintain your live document register.</p>';

    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-6">';
    echo '<h4 style="margin-top:0">Document register sections</h4>';
    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Tab</th><th>Purpose</th></tr></thead><tbody>';
    echo '<tr><td>External Docs</td><td>Documents held externally (not produced by the company).</td></tr>';
    echo '<tr><td>Accessed Docs</td><td>Information accessed online but not held by the company.</td></tr>';
    echo '<tr><td>Live Company Docs</td><td>Company-controlled procedures and standard forms.</td></tr>';
    echo '<tr><td>Old Company Docs</td><td>Superseded company documents no longer in use.</td></tr>';
    echo '<tr><td>Software</td><td>Software used for generating critical data.</td></tr>';
    echo '<tr><td>Data Storage</td><td>Where company-generated data is stored.</td></tr>';
    echo '<tr><td>Backup Info</td><td>Backup frequency and backup locations.</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="be-qms-col-6">';
    echo '<h4 style="margin-top:0">Accessed documents</h4>';
    echo '<ul>';
    foreach ($accessed_docs as $doc) {
      echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($doc['url']).'">'.esc_html($doc['title']).'</a></li>';
    }
    echo '</ul>';
    echo '<div class="be-qms-muted">Add other online references your assessor expects you to track.</div>';
    echo '</div>';
    echo '</div>';

    $save_url = esc_url(admin_url('admin-post.php'));
    echo '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(30,41,59,.75)">';
    echo '<h4>Company profile (used for consistency)</h4>';
    echo '<form method="post" action="'.$save_url.'" class="be-qms-grid">';
    echo '<input type="hidden" name="action" value="be_qms_save_profile">';
    echo '<input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('be_qms_profile')).'">';

    $fields = [
      ['company_name','Company name'],
      ['responsible_person','Responsible person'],
      ['address','Address'],
      ['phone','Phone'],
      ['email','Email'],
      ['company_reg','Company reg no.'],
      ['mcs_reg','MCS reg no.'],
      ['consumer_code','Consumer code (e.g. HIES/RECC)'],
    ];

    foreach ($fields as $f) {
      [$key,$label] = $f;
      $val = $profile[$key] ?? '';
      echo '<div class="be-qms-col-6">';
      echo '<label style="display:block;font-size:12px" class="be-qms-muted">'.esc_html($label).'</label>';
      echo '<input class="be-qms-input" name="'.$key.'" value="'.esc_attr($val).'" />';
      echo '</div>';
    }

    echo '<div class="be-qms-col-12 be-qms-row" style="margin-top:10px">';
    echo '<button class="be-qms-btn" type="submit">Save profile</button>';
    echo '</div>';
    echo '</form>';

    echo '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(30,41,59,.75)">';
    echo '<h4>MCS evidence map (quick)</h4>';
    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Requirement area</th><th>Where you evidence it in the portal</th></tr></thead><tbody>';
    echo '<tr><td>Internal review</td><td>Records → R05 Internal Review Record</td></tr>';
    echo '<tr><td>Contract review</td><td>Records → R01 Contracts Folder (use a record per job/contract)</td></tr>';
    echo '<tr><td>Goods-in checks</td><td>Records → R03 Purchase Order (link to a Project if it relates to one)</td></tr>';
    echo '<tr><td>Complaints handling</td><td>Records → R06 Customer Complaints (link to Project if applicable)</td></tr>';
    echo '<tr><td>Corrective / preventive action</td><td>Records → R02 CAPA (link to Project if applicable)</td></tr>';
    echo '<tr><td>Training / competence</td><td>Records → R07 Training Matrix</td></tr>';
    echo '<tr><td>Tools / calibration</td><td>Records → R04 Tool Calibration</td></tr>';
    echo '<tr><td>Assessment project file</td><td>Projects → evidence uploads + linked records</td></tr>';
    echo '</tbody></table>';

    echo '</div>';
  }

  private static function render_assessment() {
    echo '<div class="be-qms-card-inner">';
    echo '<h3>How to pass assessment (practical)</h3>';
    echo '<ol>';
    echo '<li>Create at least <strong>1 complete Project</strong> entry and upload evidence (design report, schematic, DNO, building regs, commissioning, photos).</li>';
    echo '<li>Create at least <strong>1 R05 Internal Review</strong> record (minutes + actions).</li>';
    echo '<li>Have <strong>R07 Training</strong> entries for you + any installers/subcontractors you use.</li>';
    echo '<li>Keep <strong>R04 Tools/Calibration</strong> up to date.</li>';
    echo '<li>Have a <strong>R06 Complaints</strong> log (even “No complaints to date” is fine — log it).</li>';
    echo '</ol>';
    echo '<p class="be-qms-muted">Tip: export key records to DOC or Print to PDF for your assessor pack.</p>';
    echo '</div>';
  }

  /* -------------------------------
   * Records
   * ------------------------------*/

  private static function render_records() {
    $action = self::get_portal_action();

    $defs = self::record_type_definitions();
    $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';
    if (!$type || empty($defs[$type])) {
      $type = array_key_first($defs);
    }

    // Special record types with custom flows
    $is_r07 = ($type === 'r07_training_matrix');
    $is_r04 = ($type === 'r04_tool_calibration');
    $is_r06 = ($type === 'r06_customer_complaints');
    $is_r05 = ($type === 'r05_internal_review');
    $is_r02 = ($type === 'r02_capa');
    $is_r03 = ($type === 'r03_purchase_order');
    $is_r08 = ($type === 'r08_approved_suppliers');
    $is_r09 = ($type === 'r09_approved_subcontract');
    $is_r11 = ($type === 'r11_company_documents');

    // --- Action pages (no sidebar) ---
    if ($is_r07 && in_array($action, ['new_employee','edit_employee','employee','add_skill','edit_skill'], true)) {
      self::render_r07_training_matrix($action);
      return;
    }

    if ($is_r04) {
      if ($action === 'new') {
        self::render_r04_tool_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r04_tool_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r04_tool_view((int) $_GET['id']);
        return;
      }
    }

    if ($is_r05) {
      if ($action === 'new') {
        self::render_r05_internal_review_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r05_internal_review_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r05_internal_review_view((int) $_GET['id']);
        return;
      }
    }

    if ($is_r02) {
      if ($action === 'new') {
        self::render_r02_capa_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r02_capa_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r02_capa_view((int) $_GET['id']);
        return;
      }
    }

    if ($is_r03) {
      if ($action === 'new') {
        self::render_r03_purchase_order_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r03_purchase_order_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r03_purchase_order_view((int) $_GET['id']);
        return;
      }
      if ($action === 'templates') {
        self::render_r03_purchase_order_templates();
        return;
      }
      if ($action === 'uploads' && !empty($_GET['id'])) {
        self::render_r03_purchase_order_uploads((int) $_GET['id']);
        return;
      }
      if ($action === 'add_upload' && !empty($_GET['id'])) {
        self::render_r03_purchase_order_upload_form((int) $_GET['id']);
        return;
      }
    }

    if ($is_r08) {
      if ($action === 'new') {
        self::render_r08_supplier_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r08_supplier_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r08_supplier_view((int) $_GET['id']);
        return;
      }
    }

    if ($is_r09) {
      if ($action === 'new') {
        self::render_r09_subcontractor_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r09_subcontractor_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r09_subcontractor_view((int) $_GET['id']);
        return;
      }
    }

    if ($is_r11) {
      if ($action === 'new') {
        self::render_r11_company_documents_form(0);
        return;
      }
      if ($action === 'edit' && !empty($_GET['id'])) {
        self::render_r11_company_documents_form((int) $_GET['id']);
        return;
      }
      if ($action === 'view' && !empty($_GET['id'])) {
        self::render_r11_company_documents_view((int) $_GET['id']);
        return;
      }
    }

    if ($action === 'new') {
      if ($is_r06) {
        self::render_r06_customer_complaints_form(0);
        return;
      }
      self::render_record_form(0);
      return;
    }
    if ($action === 'edit' && !empty($_GET['id'])) {
      if ($is_r06) {
        self::render_r06_customer_complaints_form((int) $_GET['id']);
        return;
      }
      self::render_record_form((int) $_GET['id']);
      return;
    }
    if ($action === 'view' && !empty($_GET['id'])) {
      if ($is_r06) {
        self::render_r06_customer_complaints_view((int) $_GET['id']);
        return;
      }
      self::render_record_view((int) $_GET['id']);
      return;
    }

    // --- List page (with sidebar) ---
    echo '<div class="be-qms-split">';

    // Sidebar
    echo '<div class="be-qms-side">';
    echo '<div class="be-qms-side-title">Record types</div>';
    echo '<div class="be-qms-side-list">';
    foreach ($defs as $slug => $label) {
      $cls = 'be-qms-side-item' . ($slug === $type ? ' is-active' : '');
      $url = esc_url(add_query_arg(['view'=>'records','type'=>$slug], self::portal_url()));
      echo '<a class="' . esc_attr($cls) . '" href="' . $url . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '</div>';

    // Main
    echo '<div>';

    if ($is_r07) {
      // Custom list view for R07
      self::render_r07_training_matrix($action);
      echo '</div></div>';
      return;
    }

    if ($is_r04) {
      self::render_r04_tool_list();
      echo '</div></div>';
      return;
    }

    if ($is_r05) {
      self::render_r05_internal_review_list();
      echo '</div></div>';
      return;
    }

    if ($is_r02) {
      self::render_r02_capa_list();
      echo '</div></div>';
      return;
    }

    if ($is_r03) {
      self::render_r03_purchase_order_list();
      echo '</div></div>';
      return;
    }

    if ($is_r08) {
      self::render_r08_supplier_list();
      echo '</div></div>';
      return;
    }

    if ($is_r09) {
      self::render_r09_subcontractor_list();
      echo '</div></div>';
      return;
    }

    if ($is_r11) {
      self::render_r11_company_documents_list();
      echo '</div></div>';
      return;
    }

    // Generic records list
    $label = $defs[$type] ?? 'Records';

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><strong>' . esc_html($label) . '</strong> <span class="be-qms-muted">(records)</span></div>';
    echo '<div class="be-qms-row">';
    $new_url = esc_url(add_query_arg(['view'=>'records','type'=>$type,'be_action'=>'new'], self::portal_url()));
    echo '<a class="be-qms-btn" href="'.$new_url.'">Add New</a>';
    echo '</div>';
    echo '</div>';

    // List records
    $args = [
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 100,
      'orderby' => 'date',
      'order' => 'DESC'
    ];
    if ($type) {
      $args['tax_query'] = [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => [$type]
      ]];
    }
    $q = new WP_Query($args);

    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Date</th><th>Project</th><th>Title</th><th>Actions</th></tr></thead><tbody>';

    if (!$q->have_posts()) {
      echo '<tr><td colspan="4" class="be-qms-muted">No records yet. Click “Add New”.</td></tr>';
    } else {
      while ($q->have_posts()) {
        $q->the_post();
        $pid = get_the_ID();

        $view_url  = esc_url(add_query_arg(['view'=>'records','type'=>$type,'be_action'=>'view','id'=>$pid], self::portal_url()));
        $edit_url  = esc_url(add_query_arg(['view'=>'records','type'=>$type,'be_action'=>'edit','id'=>$pid], self::portal_url()));
        $doc_url   = esc_url(add_query_arg(['be_qms_export'=>'doc','post'=>$pid], self::portal_url()));
        $print_url = esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$pid], self::portal_url()));
        $del_url   = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$pid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$pid)));

        $linked_project = (int) get_post_meta($pid, self::META_PROJECT_LINK, true);

        echo '<tr>';
        $record_date = get_post_meta($pid, '_be_qms_record_date', true) ?: get_the_date('Y-m-d');
        echo '<td>'.esc_html(self::format_date_for_display($record_date)).'</td>';

        if ($linked_project) {
          $pr_title = get_the_title($linked_project);
          $pr_url = esc_url(add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$linked_project], self::portal_url()));
          echo '<td><a class="be-qms-link" href="'.$pr_url.'">'.esc_html($pr_title ?: ('Project #'.$linked_project)).'</a></td>';
        } else {
          echo '<td class="be-qms-muted">—</td>';
        }

        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html(get_the_title()).'</a></td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$doc_url.'">DOC</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print/PDF</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Delete this record?\')">Delete</a>'
          .'</td>';
        echo '</tr>';
      }
      wp_reset_postdata();
    }

    echo '</tbody></table>';

    echo '</div></div>';
  }


  private static function render_record_form($id = 0) {
    $is_edit = $id > 0;

    $defs = self::record_type_definitions();

    $pref_type = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';
    if (!$pref_type || !isset($defs[$pref_type])) {
      $pref_type = array_key_first($defs);
    }

    $pref_project = isset($_GET['project_id']) ? (int) $_GET['project_id'] : (int) ($_GET['install_id'] ?? 0);

    $title = '';
    $date = date('Y-m-d');
    $details = '';
    $actions = '';
    $selected_type = $pref_type;
    $linked_project = $pref_project;
    $linked_subcontractor = 0;
    $existing_att_ids = [];

    if ($is_edit) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_RECORD) {
        echo '<div class="be-qms-muted">Record not found.</div>';
        return;
      }
      if (!current_user_can('edit_post', $id)) {
        wp_die('You do not have permission to edit this record.');
      }

      $title = $p->post_title;
      $date = get_post_meta($id, '_be_qms_record_date', true) ?: $date;
      $details = (string) get_post_meta($id, '_be_qms_details', true);
      $actions = (string) get_post_meta($id, '_be_qms_actions', true);
      $linked_project = (int) get_post_meta($id, self::META_PROJECT_LINK, true);
      $linked_subcontractor = (int) get_post_meta($id, '_be_qms_subcontractor_id', true);
      $existing_att_ids = get_post_meta($id, '_be_qms_attachments', true);
      if (!is_array($existing_att_ids)) $existing_att_ids = [];

      $terms = get_the_terms($id, self::TAX_RECORD_TYPE);
      if ($terms && !is_wp_error($terms)) {
        $selected_type = $terms[0]->slug;
      }
    }

    $projects = get_posts([
      'post_type' => self::CPT_PROJECT,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $subcontractors = self::query_r09_subcontractors();

    echo '<h3>'.($is_edit ? 'Edit record' : 'New record').'</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    wp_nonce_field('be_qms_save_record');

    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Record type</strong><br/>';
    echo '<select class="be-qms-select" name="record_type" required>';
    foreach ($defs as $slug => $name) {
      $sel = ($slug === $selected_type) ? 'selected' : '';
      echo '<option '.$sel.' value="'.esc_attr($slug).'">'.esc_html($name).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="record_date" value="'.esc_attr(self::format_date_for_display($date)).'" placeholder="DD/MM/YYYY" required /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Title</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="title" value="'.esc_attr($title).'" placeholder="e.g. Internal Review – Q1 2026" required /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Linked project</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<select class="be-qms-select" name="project_id">';
    echo '<option value="0">— Company record (not tied to a job) —</option>';
    if (!empty($projects)) {
      foreach ($projects as $pr) {
        $sel = ($linked_project && (int)$linked_project === (int)$pr->ID) ? 'selected' : '';
        echo '<option '.$sel.' value="'.esc_attr($pr->ID).'">'.esc_html($pr->post_title).'</option>';
      }
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Subcontractor</strong> <span class="be-qms-muted">(optional, for project-linked records)</span><br/>';
    echo '<select class="be-qms-select" name="subcontractor_id">';
    echo '<option value="0">— None selected —</option>';
    if (!empty($subcontractors)) {
      foreach ($subcontractors as $subcontractor) {
        $sel = ($linked_subcontractor && (int)$linked_subcontractor === (int)$subcontractor->ID) ? 'selected' : '';
        $name = get_post_meta($subcontractor->ID, '_be_qms_r09_subcontractor_name', true) ?: $subcontractor->post_title;
        echo '<option '.$sel.' value="'.esc_attr($subcontractor->ID).'">'.esc_html($name).'</option>';
      }
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Details</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="details" required>'.esc_textarea($details).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Actions / follow-ups</strong> <span class="be-qms-muted">(who / by when)</span><br/>';
    echo '<textarea class="be-qms-textarea" name="actions">'.esc_textarea($actions).'</textarea></label></div>';

    // Existing attachments
    echo '<div class="be-qms-col-12">';
    echo '<label><strong>Attachments</strong></label>';

    if ($is_edit && !empty($existing_att_ids)) {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">Tick any files you want to remove, and/or upload more below.</div>';
      echo '<ul style="margin:0;padding-left:18px">';
      foreach ($existing_att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        if (!$url) continue;
        $name = get_the_title($aid) ?: basename((string)$url);
        echo '<li style="margin:6px 0">'
          .'<label style="display:flex;gap:10px;align-items:center">'
          .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr((int)$aid).'">'
          .'<a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name).'</a>'
          .'</label>'
          .'</li>';
      }
      echo '</ul>';
    } else {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">None yet.</div>';
    }

    echo '<div style="margin-top:8px">';
    echo '<input type="file" name="attachments[]" multiple />';
    echo '</div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save record').'</button>';

    $back_type = isset($defs[$selected_type]) ? $selected_type : $pref_type;
    $back = esc_url(add_query_arg(['view'=>'records','type'=>$back_type], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r06_customer_complaints_form($id = 0) {
    $is_edit = $id > 0;

    $customer_name = '';
    $address = '';
    $complaint_date = date('Y-m-d');
    $contact_name = '';
    $contact_email = '';
    $contact_phone = '';
    $contact_mobile = '';
    $nature = '';
    $outcome = '';
    $immediate_action = '';
    $contacted_within_day = '';
    $contacted_reason = '';
    $actions_taken = '';
    $further_action = '';
    $customer_satisfied = '';
    $reported_by = '';
    $date_closed = '';
    $reported_title = '';
    $linked_project = 0;
    $existing_att_ids = [];

    if ($is_edit) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_RECORD) {
        echo '<div class="be-qms-muted">Record not found.</div>';
        return;
      }
      if (!current_user_can('edit_post', $id)) {
        wp_die('You do not have permission to edit this record.');
      }

      $customer_name = (string) get_post_meta($id, '_be_qms_r06_customer_name', true);
      $address = (string) get_post_meta($id, '_be_qms_r06_address', true);
      $complaint_date = get_post_meta($id, '_be_qms_r06_complaint_date', true) ?: $complaint_date;
      $contact_name = (string) get_post_meta($id, '_be_qms_r06_contact_name', true);
      $contact_email = (string) get_post_meta($id, '_be_qms_r06_contact_email', true);
      $contact_phone = (string) get_post_meta($id, '_be_qms_r06_contact_phone', true);
      $contact_mobile = (string) get_post_meta($id, '_be_qms_r06_contact_mobile', true);
      $nature = (string) get_post_meta($id, '_be_qms_r06_nature', true);
      $outcome = (string) get_post_meta($id, '_be_qms_r06_outcome', true);
      $immediate_action = (string) get_post_meta($id, '_be_qms_r06_immediate_action', true);
      $contacted_within_day = (string) get_post_meta($id, '_be_qms_r06_contacted_within_day', true);
      $contacted_reason = (string) get_post_meta($id, '_be_qms_r06_contacted_reason', true);
      $actions_taken = (string) get_post_meta($id, '_be_qms_r06_actions_taken', true);
      $further_action = (string) get_post_meta($id, '_be_qms_r06_further_action', true);
      $customer_satisfied = (string) get_post_meta($id, '_be_qms_r06_customer_satisfied', true);
      $reported_by = (string) get_post_meta($id, '_be_qms_r06_reported_by', true);
      $date_closed = (string) get_post_meta($id, '_be_qms_r06_date_closed', true);
      $reported_title = (string) get_post_meta($id, '_be_qms_r06_reported_title', true);
      $linked_project = (int) get_post_meta($id, self::META_PROJECT_LINK, true);
      $existing_att_ids = get_post_meta($id, '_be_qms_attachments', true);
      if (!is_array($existing_att_ids)) $existing_att_ids = [];
    }

    $projects = get_posts([
      'post_type' => self::CPT_PROJECT,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    echo '<h3>R06 - Customer Complaints</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r06_customer_complaints" />';
    wp_nonce_field('be_qms_save_record');

    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Customer Name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="customer_name" value="'.esc_attr($customer_name).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Address</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="address">'.esc_textarea($address).'</textarea></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date of Complaint</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="complaint_date" value="'.esc_attr(self::format_date_for_display($complaint_date)).'" placeholder="DD/MM/YYYY" /></label></div>';
    echo '<div class="be-qms-col-6"></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact&#039;s Name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="contact_name" value="'.esc_attr($contact_name).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>E-mail</strong><br/>';
    echo '<input class="be-qms-input" type="email" name="contact_email" value="'.esc_attr($contact_email).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Telephone</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="contact_phone" value="'.esc_attr($contact_phone).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Mobile</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="contact_mobile" value="'.esc_attr($contact_mobile).'" /></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    echo '<div class="be-qms-col-12"><label><strong>Nature of Complaint</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="nature" value="'.esc_attr($nature).'" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Outcome</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="outcome" value="'.esc_attr($outcome).'" /></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    echo '<div class="be-qms-col-12"><label><strong>Immediate Action Requested by Customer</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="immediate_action">'.esc_textarea($immediate_action).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    $contacted_yes = ($contacted_within_day === 'yes') ? 'checked' : '';
    $contacted_no = ($contacted_within_day === 'no') ? 'checked' : '';
    echo '<div class="be-qms-col-12"><label><strong>Customer contacted within 1 working day and given information as to when the problem will be resolved?</strong></label>';
    echo '<div class="be-qms-row" style="gap:28px;margin-top:6px">';
    echo '<label><input type="radio" name="contacted_within_day" value="yes" '.$contacted_yes.'> Yes</label>';
    echo '<label><input type="radio" name="contacted_within_day" value="no" '.$contacted_no.'> No</label>';
    echo '</div></div>';

    echo '<div class="be-qms-col-12"><label><strong>If not, why not?</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="contacted_reason">'.esc_textarea($contacted_reason).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    echo '<div class="be-qms-col-12"><label><strong>Actions taken to resolve complaint</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="actions_taken">'.esc_textarea($actions_taken).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Further Action Required</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="further_action">'.esc_textarea($further_action).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    $satisfied_yes = ($customer_satisfied === 'yes') ? 'checked' : '';
    $satisfied_no = ($customer_satisfied === 'no') ? 'checked' : '';
    echo '<div class="be-qms-col-12"><label><strong>Is Customer satisfied with result?</strong></label>';
    echo '<div class="be-qms-row" style="gap:28px;margin-top:6px">';
    echo '<label><input type="radio" name="customer_satisfied" value="yes" '.$satisfied_yes.'> Yes</label>';
    echo '<label><input type="radio" name="customer_satisfied" value="no" '.$satisfied_no.'> No</label>';
    echo '</div></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    echo '<div class="be-qms-col-6"><label><strong>Reported By</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="reported_by" value="'.esc_attr($reported_by).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date Closed</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="date_closed" value="'.esc_attr(self::format_date_for_display($date_closed)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Title</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="reported_title" value="'.esc_attr($reported_title).'" /></label></div>';
    echo '<div class="be-qms-col-6"></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';

    echo '<div class="be-qms-col-12"><label><strong>Linked project</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<select class="be-qms-select" name="project_id">';
    echo '<option value="0">— Company record (not tied to a job) —</option>';
    if (!empty($projects)) {
      foreach ($projects as $pr) {
        $sel = ($linked_project && (int)$linked_project === (int)$pr->ID) ? 'selected' : '';
        echo '<option '.$sel.' value="'.esc_attr($pr->ID).'">'.esc_html($pr->post_title).'</option>';
      }
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12">';
    echo '<label><strong>Attachments</strong></label>';

    if ($is_edit && !empty($existing_att_ids)) {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">Tick any files you want to remove, and/or upload more below.</div>';
      echo '<ul style="margin:0;padding-left:18px">';
      foreach ($existing_att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        if (!$url) continue;
        $name = get_the_title($aid) ?: basename((string)$url);
        echo '<li style="margin:6px 0">'
          .'<label style="display:flex;gap:10px;align-items:center">'
          .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr((int)$aid).'">'
          .'<a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name).'</a>'
          .'</label>'
          .'</li>';
      }
      echo '</ul>';
    } else {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">None yet.</div>';
    }

    echo '<div style="margin-top:8px">';
    echo '<input type="file" name="attachments[]" multiple />';
    echo '</div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save record').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r06_customer_complaints'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r06_customer_complaints_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $customer_name = (string) get_post_meta($id, '_be_qms_r06_customer_name', true);
    $address = (string) get_post_meta($id, '_be_qms_r06_address', true);
    $complaint_date = (string) get_post_meta($id, '_be_qms_r06_complaint_date', true);
    $contact_name = (string) get_post_meta($id, '_be_qms_r06_contact_name', true);
    $contact_email = (string) get_post_meta($id, '_be_qms_r06_contact_email', true);
    $contact_phone = (string) get_post_meta($id, '_be_qms_r06_contact_phone', true);
    $contact_mobile = (string) get_post_meta($id, '_be_qms_r06_contact_mobile', true);
    $nature = (string) get_post_meta($id, '_be_qms_r06_nature', true);
    $outcome = (string) get_post_meta($id, '_be_qms_r06_outcome', true);
    $immediate_action = (string) get_post_meta($id, '_be_qms_r06_immediate_action', true);
    $contacted_within_day = (string) get_post_meta($id, '_be_qms_r06_contacted_within_day', true);
    $contacted_reason = (string) get_post_meta($id, '_be_qms_r06_contacted_reason', true);
    $actions_taken = (string) get_post_meta($id, '_be_qms_r06_actions_taken', true);
    $further_action = (string) get_post_meta($id, '_be_qms_r06_further_action', true);
    $customer_satisfied = (string) get_post_meta($id, '_be_qms_r06_customer_satisfied', true);
    $reported_by = (string) get_post_meta($id, '_be_qms_r06_reported_by', true);
    $date_closed = (string) get_post_meta($id, '_be_qms_r06_date_closed', true);
    $reported_title = (string) get_post_meta($id, '_be_qms_r06_reported_title', true);
    $linked_project = (int) get_post_meta($id, self::META_PROJECT_LINK, true);
    $linked_subcontractor = (int) get_post_meta($id, '_be_qms_subcontractor_id', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $doc_url  = esc_url(add_query_arg(['be_qms_export'=>'doc','post'=>$id], self::portal_url()));
    $print_url= esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$id], self::portal_url()));
    $edit_url = esc_url(add_query_arg(['view'=>'records','be_action'=>'edit','id'=>$id,'type'=>'r06_customer_complaints'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R06 - Customer Complaints</h3>';
    $display_complaint_date = $complaint_date ?: get_the_date('Y-m-d', $id);
    echo '<div class="be-qms-muted">'.esc_html(self::format_date_for_display($display_complaint_date)).'</div>';

    if ($linked_project) {
      $pt = get_the_title($linked_project);
      $purl = esc_url(add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$linked_project], self::portal_url()));
      echo '<div class="be-qms-muted">Linked project: <a class="be-qms-link" href="'.$purl.'">'.esc_html($pt ?: ('Project #'.$linked_project)).'</a></div>';
    }
    if ($linked_subcontractor) {
      $subcontractor_name = get_post_meta($linked_subcontractor, '_be_qms_r09_subcontractor_name', true) ?: get_the_title($linked_subcontractor);
      if ($subcontractor_name) {
        echo '<div class="be-qms-muted">Subcontractor: '.esc_html($subcontractor_name).'</div>';
      }
    }

    echo '</div>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$doc_url.'">Download DOC</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print / Save PDF</a>'
      .'</div>';
    echo '</div>';

    echo '<div class="be-qms-card-inner">';
    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-6"><strong>Customer Name</strong><br/>'.esc_html($customer_name).'</div>';
    echo '<div class="be-qms-col-6"><strong>Address</strong><br/>'.wpautop(esc_html($address)).'</div>';
    echo '<div class="be-qms-col-6"><strong>Date of Complaint</strong><br/>'.esc_html(self::format_date_for_display($complaint_date)).'</div>';
    echo '<div class="be-qms-col-6"><strong>Contact&#039;s Name</strong><br/>'.esc_html($contact_name).'</div>';
    echo '<div class="be-qms-col-6"><strong>E-mail</strong><br/>'.esc_html($contact_email).'</div>';
    echo '<div class="be-qms-col-6"><strong>Telephone</strong><br/>'.esc_html($contact_phone).'</div>';
    echo '<div class="be-qms-col-6"><strong>Mobile</strong><br/>'.esc_html($contact_mobile).'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Nature of Complaint</strong><br/>'.esc_html($nature).'</div>';
    echo '<div class="be-qms-col-12"><strong>Outcome</strong><br/>'.esc_html($outcome).'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Immediate Action Requested by Customer</strong><br/>'.wpautop(esc_html($immediate_action)).'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Customer contacted within 1 working day?</strong><br/>'.esc_html($contacted_within_day ? ucfirst($contacted_within_day) : '—').'</div>';
    echo '<div class="be-qms-col-12"><strong>If not, why not?</strong><br/>'.wpautop(esc_html($contacted_reason)).'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Actions taken to resolve complaint</strong><br/>'.wpautop(esc_html($actions_taken)).'</div>';
    echo '<div class="be-qms-col-12"><strong>Further Action Required</strong><br/>'.wpautop(esc_html($further_action)).'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Is Customer satisfied with result?</strong><br/>'.esc_html($customer_satisfied ? ucfirst($customer_satisfied) : '—').'</div>';
    echo '<div class="be-qms-col-12"><hr style="margin:12px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-6"><strong>Reported By</strong><br/>'.esc_html($reported_by).'</div>';
    echo '<div class="be-qms-col-6"><strong>Date Closed</strong><br/>'.esc_html(self::format_date_for_display($date_closed)).'</div>';
    echo '<div class="be-qms-col-6"><strong>Title</strong><br/>'.esc_html($reported_title).'</div>';
    echo '</div>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r06_customer_complaints'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  private static function render_record_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $terms = get_the_terms($id, self::TAX_RECORD_TYPE);
    $type_slug = ($terms && !is_wp_error($terms)) ? $terms[0]->slug : '';
    $defs = self::record_type_definitions();
    $type_name = ($type_slug && isset($defs[$type_slug])) ? $defs[$type_slug] : (($terms && !is_wp_error($terms)) ? $terms[0]->name : '-');

    $record_date = get_post_meta($id, '_be_qms_record_date', true) ?: get_the_date('Y-m-d', $id);
    $details = (string) get_post_meta($id, '_be_qms_details', true);
    $actions = (string) get_post_meta($id, '_be_qms_actions', true);
    $linked_project = (int) get_post_meta($id, self::META_PROJECT_LINK, true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $doc_url  = esc_url(add_query_arg(['be_qms_export'=>'doc','post'=>$id], self::portal_url()));
    $print_url= esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$id], self::portal_url()));
    $edit_url = esc_url(add_query_arg(['view'=>'records','be_action'=>'edit','id'=>$id,'type'=>$type_slug], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">'.esc_html($p->post_title).'</h3>';
    echo '<div class="be-qms-muted">'.esc_html($type_name).' • '.esc_html(self::format_date_for_display($record_date)).'</div>';

    if ($linked_project) {
      $pt = get_the_title($linked_project);
      $purl = esc_url(add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$linked_project], self::portal_url()));
      echo '<div class="be-qms-muted">Linked project: <a class="be-qms-link" href="'.$purl.'">'.esc_html($pt ?: ('Project #'.$linked_project)).'</a></div>';
    }

    echo '</div>';

    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$doc_url.'">Download DOC</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print / Save PDF</a>'
      .'</div>';
    echo '</div>';

    echo '<div class="be-qms-card-inner">';
    echo '<h4>Details</h4>';
    echo '<div>'.wpautop(esc_html($details)).'</div>';

    if (!empty($actions)) {
      echo '<h4 style="margin-top:14px">Actions</h4>';
      echo '<div>'.wpautop(esc_html($actions)).'</div>';
    }

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>($type_slug ?: array_key_first(self::record_type_definitions()))], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R04 Tool Calibration
  // -------------------------

  private static function render_r04_tool_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><strong>R04 Tool Calibration</strong> <span class="be-qms-muted">(tools register)</span></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $tools = self::query_r04_tools();

    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Item</th><th>Serial No</th><th>Next Due</th><th>Actions</th></tr></thead><tbody>';

    if (!$tools) {
      echo '<tr><td colspan="4" class="be-qms-muted">No tools logged yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($tools as $tool) {
        $rid = (int) $tool->ID;
        $item = get_post_meta($rid, '_be_qms_tool_item', true) ?: get_the_title($rid);
        $serial = get_post_meta($rid, '_be_qms_tool_serial', true);
        $next_due = get_post_meta($rid, '_be_qms_tool_next_due', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        echo '<tr>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html($item).'</a></td>';
        echo '<td>'.esc_html($serial ?: '—').'</td>';
        $display_next_due = $next_due ? self::format_date_for_display($next_due) : '—';
        echo '<td>'.esc_html($display_next_due).'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this tool record?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r04_tools() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r04_tool_calibration'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r04_tool_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Tool record not found.</div>';
      return;
    }

    $item = $is_edit ? (get_post_meta($id, '_be_qms_tool_item', true) ?: $p->post_title) : '';
    $serial = $is_edit ? get_post_meta($id, '_be_qms_tool_serial', true) : '';
    $description = $is_edit ? get_post_meta($id, '_be_qms_tool_description', true) : '';
    $requirements = $is_edit ? get_post_meta($id, '_be_qms_tool_requirements', true) : '';
    $date_purchased = $is_edit ? get_post_meta($id, '_be_qms_tool_date_purchased', true) : '';
    $date_calibrated = $is_edit ? get_post_meta($id, '_be_qms_tool_date_calibrated', true) : '';
    $next_due = $is_edit ? get_post_meta($id, '_be_qms_tool_next_due', true) : '';
    $linked_project = $is_edit ? (int) get_post_meta($id, self::META_PROJECT_LINK, true) : 0;

    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];
    $projects = get_posts([
      'post_type' => self::CPT_PROJECT,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R04 - Tool Calibration</h3>';
    echo '<div class="be-qms-muted">'.($is_edit ? 'Edit tool record' : 'Add new tool record').'</div></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r04_tool_calibration" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Item of Equipment</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="tool_item" value="'.esc_attr($item).'" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Serial Number</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="tool_serial" value="'.esc_attr($serial).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Calibration / Checking Requirements</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="tool_requirements" value="'.esc_attr($requirements).'" placeholder="e.g. Annual calibration" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Description / Notes</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="tool_description" value="'.esc_attr($description).'" /></label></div>';

    echo '<div class="be-qms-col-12" style="height:6px"></div>';

    echo '<div class="be-qms-col-4"><label><strong>Date Purchased</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="tool_date_purchased" value="'.esc_attr(self::format_date_for_display($date_purchased)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Date Calibrated</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="tool_date_calibrated" value="'.esc_attr(self::format_date_for_display($date_calibrated)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Next Calibration Date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="tool_next_due" value="'.esc_attr(self::format_date_for_display($next_due)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Linked project</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<select class="be-qms-select" name="project_id">';
    echo '<option value="0">— Company record (not tied to a job) —</option>';
    if (!empty($projects)) {
      foreach ($projects as $pr) {
        $sel = ($linked_project && (int) $linked_project === (int) $pr->ID) ? 'selected' : '';
        echo '<option '.$sel.' value="'.esc_attr($pr->ID).'">'.esc_html($pr->post_title).'</option>';
      }
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Upload evidence</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>'; // grid

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save & Close').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r04_tool_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Tool record not found.</div>';
      return;
    }

    $item = get_post_meta($id, '_be_qms_tool_item', true) ?: $p->post_title;
    $serial = get_post_meta($id, '_be_qms_tool_serial', true);
    $description = get_post_meta($id, '_be_qms_tool_description', true);
    $requirements = get_post_meta($id, '_be_qms_tool_requirements', true);
    $date_purchased = get_post_meta($id, '_be_qms_tool_date_purchased', true);
    $date_calibrated = get_post_meta($id, '_be_qms_tool_date_calibrated', true);
    $next_due = get_post_meta($id, '_be_qms_tool_next_due', true);

    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R04 - Tool Calibration</h3>';
    echo '<div class="be-qms-muted">'.esc_html($item).'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Item</th><td>'.esc_html($item).'</td></tr>';
    echo '<tr><th>Serial</th><td>'.esc_html($serial ?: '—').'</td></tr>';
    echo '<tr><th>Requirements</th><td>'.esc_html($requirements ?: '—').'</td></tr>';
    echo '<tr><th>Description</th><td>'.esc_html($description ?: '—').'</td></tr>';
    $display_purchased = $date_purchased ? self::format_date_for_display($date_purchased) : '—';
    $display_calibrated = $date_calibrated ? self::format_date_for_display($date_calibrated) : '—';
    $display_next_due = $next_due ? self::format_date_for_display($next_due) : '—';
    echo '<tr><th>Date Purchased</th><td>'.esc_html($display_purchased).'</td></tr>';
    echo '<tr><th>Date Calibrated</th><td>'.esc_html($display_calibrated).'</td></tr>';
    echo '<tr><th>Next Due</th><td>'.esc_html($display_next_due).'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r04_tool_calibration'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R05 Internal Review Record
  // -------------------------

  private static function render_r05_internal_review_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R05 - Internal Review Record</h3>'
      .'<div class="be-qms-muted">Log internal review meetings, findings, and actions.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $records = self::query_r05_internal_reviews();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Review Date</th><th>Period</th><th>Reviewer</th><th>Status</th><th>Options</th></tr></thead><tbody>';

    if (!$records) {
      echo '<tr><td colspan="5" class="be-qms-muted">No internal reviews yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($records as $record) {
        $rid = (int) $record->ID;
        $review_date = get_post_meta($rid, '_be_qms_r05_review_date', true);
        $period_from = get_post_meta($rid, '_be_qms_r05_period_from', true);
        $period_to = get_post_meta($rid, '_be_qms_r05_period_to', true);
        $reviewer = get_post_meta($rid, '_be_qms_r05_reviewer', true);
        $status = get_post_meta($rid, '_be_qms_r05_status', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_review = $review_date ? self::format_date_for_display($review_date) : '—';
        $period_bits = array_filter([
          $period_from ? self::format_date_for_display($period_from) : '',
          $period_to ? self::format_date_for_display($period_to) : '',
        ]);
        $display_period = $period_bits ? implode(' – ', $period_bits) : '—';

        echo '<tr>';
        echo '<td>'.esc_html($display_review).'</td>';
        echo '<td>'.esc_html($display_period).'</td>';
        echo '<td>'.esc_html($reviewer ?: '—').'</td>';
        echo '<td>'.esc_html($status ?: '—').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this internal review?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r05_internal_reviews() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r05_internal_review'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r05_internal_review_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $review_date = $is_edit ? get_post_meta($id, '_be_qms_r05_review_date', true) : '';
    $period_from = $is_edit ? get_post_meta($id, '_be_qms_r05_period_from', true) : '';
    $period_to = $is_edit ? get_post_meta($id, '_be_qms_r05_period_to', true) : '';
    $reviewer = $is_edit ? get_post_meta($id, '_be_qms_r05_reviewer', true) : '';
    $attendees = $is_edit ? get_post_meta($id, '_be_qms_r05_attendees', true) : '';
    $scope = $is_edit ? get_post_meta($id, '_be_qms_r05_scope', true) : '';
    $findings = $is_edit ? get_post_meta($id, '_be_qms_r05_findings', true) : '';
    $nonconformities = $is_edit ? get_post_meta($id, '_be_qms_r05_nonconformities', true) : '';
    $actions = $is_edit ? get_post_meta($id, '_be_qms_r05_actions', true) : '';
    $decision = $is_edit ? get_post_meta($id, '_be_qms_r05_decision', true) : '';
    $next_review_date = $is_edit ? get_post_meta($id, '_be_qms_r05_next_review_date', true) : '';
    $status = $is_edit ? get_post_meta($id, '_be_qms_r05_status', true) : '';
    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R05 - Internal Review Record</h3>';
    echo '<div class="be-qms-muted">'.($is_edit ? 'Edit internal review' : 'Add internal review').'</div></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r05_internal_review" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-4"><label><strong>Review date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r05_review_date" value="'.esc_attr(self::format_date_for_display($review_date)).'" placeholder="DD/MM/YYYY" required /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Period from</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r05_period_from" value="'.esc_attr(self::format_date_for_display($period_from)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Period to</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r05_period_to" value="'.esc_attr(self::format_date_for_display($period_to)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Reviewer</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r05_reviewer" value="'.esc_attr($reviewer).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Attendees</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r05_attendees" value="'.esc_attr($attendees).'" placeholder="e.g. J. Smith, A. Patel" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Scope / areas reviewed</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r05_scope">'.esc_textarea($scope).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Findings summary</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r05_findings">'.esc_textarea($findings).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Nonconformities / observations</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r05_nonconformities">'.esc_textarea($nonconformities).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Actions / follow-ups</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r05_actions">'.esc_textarea($actions).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Decision / approval</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r05_decision">'.esc_textarea($decision).'</textarea></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Next review date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r05_next_review_date" value="'.esc_attr(self::format_date_for_display($next_review_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Status</strong><br/>';
    echo '<select class="be-qms-select" name="r05_status">';
    $status_options = ['Open', 'Closed'];
    echo '<option value="">— Select —</option>';
    foreach ($status_options as $option) {
      $selected = ($status === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attachments</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>'; // grid

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r05_internal_review_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $review_date = get_post_meta($id, '_be_qms_r05_review_date', true);
    $period_from = get_post_meta($id, '_be_qms_r05_period_from', true);
    $period_to = get_post_meta($id, '_be_qms_r05_period_to', true);
    $reviewer = get_post_meta($id, '_be_qms_r05_reviewer', true);
    $attendees = get_post_meta($id, '_be_qms_r05_attendees', true);
    $scope = get_post_meta($id, '_be_qms_r05_scope', true);
    $findings = get_post_meta($id, '_be_qms_r05_findings', true);
    $nonconformities = get_post_meta($id, '_be_qms_r05_nonconformities', true);
    $actions = get_post_meta($id, '_be_qms_r05_actions', true);
    $decision = get_post_meta($id, '_be_qms_r05_decision', true);
    $next_review_date = get_post_meta($id, '_be_qms_r05_next_review_date', true);
    $status = get_post_meta($id, '_be_qms_r05_status', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review','be_action'=>'edit','id'=>$id], self::portal_url()));

    $display_review = $review_date ? self::format_date_for_display($review_date) : '—';
    $period_bits = array_filter([
      $period_from ? self::format_date_for_display($period_from) : '',
      $period_to ? self::format_date_for_display($period_to) : '',
    ]);
    $display_period = $period_bits ? implode(' – ', $period_bits) : '—';
    $display_next = $next_review_date ? self::format_date_for_display($next_review_date) : '—';

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R05 - Internal Review Record</h3>';
    echo '<div class="be-qms-muted">Review date: '.esc_html($display_review).'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Review date</th><td>'.esc_html($display_review).'</td></tr>';
    echo '<tr><th>Review period</th><td>'.esc_html($display_period).'</td></tr>';
    echo '<tr><th>Reviewer</th><td>'.esc_html($reviewer ?: '—').'</td></tr>';
    echo '<tr><th>Attendees</th><td>'.esc_html($attendees ?: '—').'</td></tr>';
    echo '<tr><th>Status</th><td>'.esc_html($status ?: '—').'</td></tr>';
    echo '<tr><th>Next review date</th><td>'.esc_html($display_next).'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Scope / areas reviewed</h4>';
    echo '<div>'.wpautop(esc_html($scope)).'</div>';

    echo '<h4 style="margin-top:14px">Findings summary</h4>';
    echo '<div>'.wpautop(esc_html($findings)).'</div>';

    echo '<h4 style="margin-top:14px">Nonconformities / observations</h4>';
    echo '<div>'.wpautop(esc_html($nonconformities)).'</div>';

    echo '<h4 style="margin-top:14px">Actions / follow-ups</h4>';
    echo '<div>'.wpautop(esc_html($actions)).'</div>';

    echo '<h4 style="margin-top:14px">Decision / approval</h4>';
    echo '<div>'.wpautop(esc_html($decision)).'</div>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r05_internal_review'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R02 CAPA
  // -------------------------

  private static function render_r02_capa_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">Corrective &amp; Preventive Action Record</h3>'
      .'<div class="be-qms-muted">Your existing non conformities are displayed below. Click “Add New” to add a new corrective and preventative action.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $records = self::query_r02_capa();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Date</th><th>NCR No</th><th>Source</th><th>Action Type</th><th>Date Closed</th><th>Status</th><th>Options</th></tr></thead><tbody>';

    if (!$records) {
      echo '<tr><td colspan="7" class="be-qms-muted">No CAPA records yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($records as $record) {
        $rid = (int) $record->ID;
        $date = get_post_meta($rid, '_be_qms_capa_date', true);
        $ncr = get_post_meta($rid, '_be_qms_capa_ncr_no', true);
        $source = get_post_meta($rid, '_be_qms_capa_source', true);
        $action_type = get_post_meta($rid, '_be_qms_capa_action_type', true);
        $date_closed = get_post_meta($rid, '_be_qms_capa_date_closed', true);
        $status = get_post_meta($rid, '_be_qms_capa_status', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_date = $date ? self::format_date_for_display($date) : '—';
        $display_closed = $date_closed ? self::format_date_for_display($date_closed) : '—';

        echo '<tr>';
        echo '<td>'.esc_html($display_date).'</td>';
        echo '<td>'.esc_html($ncr ?: '—').'</td>';
        echo '<td>'.esc_html($source ?: '—').'</td>';
        echo '<td>'.esc_html($action_type ?: '—').'</td>';
        echo '<td>'.esc_html($display_closed).'</td>';
        echo '<td>'.esc_html($status ?: '—').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this CAPA record?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r02_capa() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r02_capa'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r02_capa_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $date = $is_edit ? get_post_meta($id, '_be_qms_capa_date', true) : '';
    $ncr_no = $is_edit ? get_post_meta($id, '_be_qms_capa_ncr_no', true) : '';
    $source = $is_edit ? get_post_meta($id, '_be_qms_capa_source', true) : '';
    $action_type = $is_edit ? get_post_meta($id, '_be_qms_capa_action_type', true) : '';
    $issued_to = $is_edit ? get_post_meta($id, '_be_qms_capa_issued_to', true) : '';
    $no_days = $is_edit ? get_post_meta($id, '_be_qms_capa_no_days', true) : '';
    $date_closed = $is_edit ? get_post_meta($id, '_be_qms_capa_date_closed', true) : '';
    $status = $is_edit ? get_post_meta($id, '_be_qms_capa_status', true) : '';
    $closed_by = $is_edit ? get_post_meta($id, '_be_qms_capa_closed_by', true) : '';
    $details_issue = $is_edit ? get_post_meta($id, '_be_qms_capa_details_issue', true) : '';
    $summary_action = $is_edit ? get_post_meta($id, '_be_qms_capa_summary_action', true) : '';
    $root_cause = $is_edit ? get_post_meta($id, '_be_qms_capa_root_cause', true) : '';
    $prevent_recurrence = $is_edit ? get_post_meta($id, '_be_qms_capa_prevent_recurrence', true) : '';
    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R02 - Corrective &amp; Preventive Action Record</h3></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r02_capa" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<h4 style="margin-top:8px">Source Details</h4>';
    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="capa_date" value="'.esc_attr(self::format_date_for_display($date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>NCR No (if applicable)</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="capa_ncr_no" value="'.esc_attr($ncr_no).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Source</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="capa_source" value="'.esc_attr($source).'" placeholder="e.g. Management Review" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Preventive or Corrective?</strong><br/>';
    echo '<select class="be-qms-select" name="capa_action_type">';
    $action_options = ['Preventative', 'Corrective', 'Other'];
    echo '<option value="">— Select —</option>';
    foreach ($action_options as $option) {
      $selected = ($action_type === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Issued To</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="capa_issued_to" value="'.esc_attr($issued_to).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>No of Days</strong><br/>';
    echo '<input class="be-qms-input" type="number" min="0" name="capa_no_days" value="'.esc_attr($no_days).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date Closed</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="capa_date_closed" value="'.esc_attr(self::format_date_for_display($date_closed)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Status (Open/Closed)</strong></label>';
    $status_open = ($status === 'Open') ? 'checked' : '';
    $status_closed = ($status === 'Closed') ? 'checked' : '';
    echo '<div class="be-qms-row" style="gap:28px;margin-top:6px">';
    echo '<label><input type="radio" name="capa_status" value="Open" '.$status_open.'> Open</label>';
    echo '<label><input type="radio" name="capa_status" value="Closed" '.$status_closed.'> Closed</label>';
    echo '</div></div>';

    echo '<div class="be-qms-col-6"><label><strong>Closed By</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="capa_closed_by" value="'.esc_attr($closed_by).'" /></label></div>';

    echo '</div>';

    echo '<h4 style="margin-top:16px">Action Details</h4>';
    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-12"><label><strong>Details of Issue</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="capa_details_issue">'.esc_textarea($details_issue).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Summary of Action Taken</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="capa_summary_action">'.esc_textarea($summary_action).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Root Cause</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="capa_root_cause">'.esc_textarea($root_cause).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>What can be done to prevent a recurrence?</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="capa_prevent_recurrence">'.esc_textarea($prevent_recurrence).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attachments</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r02_capa_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $date = get_post_meta($id, '_be_qms_capa_date', true);
    $ncr_no = get_post_meta($id, '_be_qms_capa_ncr_no', true);
    $source = get_post_meta($id, '_be_qms_capa_source', true);
    $action_type = get_post_meta($id, '_be_qms_capa_action_type', true);
    $issued_to = get_post_meta($id, '_be_qms_capa_issued_to', true);
    $no_days = get_post_meta($id, '_be_qms_capa_no_days', true);
    $date_closed = get_post_meta($id, '_be_qms_capa_date_closed', true);
    $status = get_post_meta($id, '_be_qms_capa_status', true);
    $closed_by = get_post_meta($id, '_be_qms_capa_closed_by', true);
    $details_issue = get_post_meta($id, '_be_qms_capa_details_issue', true);
    $summary_action = get_post_meta($id, '_be_qms_capa_summary_action', true);
    $root_cause = get_post_meta($id, '_be_qms_capa_root_cause', true);
    $prevent_recurrence = get_post_meta($id, '_be_qms_capa_prevent_recurrence', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R02 - Corrective &amp; Preventive Action Record</h3>';
    echo '<div class="be-qms-muted">'.esc_html(self::format_date_for_display($date) ?: '—').'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Date</th><td>'.esc_html(self::format_date_for_display($date) ?: '—').'</td></tr>';
    echo '<tr><th>NCR No</th><td>'.esc_html($ncr_no ?: '—').'</td></tr>';
    echo '<tr><th>Source</th><td>'.esc_html($source ?: '—').'</td></tr>';
    echo '<tr><th>Action Type</th><td>'.esc_html($action_type ?: '—').'</td></tr>';
    echo '<tr><th>Issued To</th><td>'.esc_html($issued_to ?: '—').'</td></tr>';
    echo '<tr><th>No of Days</th><td>'.esc_html($no_days ?: '—').'</td></tr>';
    echo '<tr><th>Date Closed</th><td>'.esc_html(self::format_date_for_display($date_closed) ?: '—').'</td></tr>';
    echo '<tr><th>Status</th><td>'.esc_html($status ?: '—').'</td></tr>';
    echo '<tr><th>Closed By</th><td>'.esc_html($closed_by ?: '—').'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Action Details</h4>';
    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-12"><strong>Details of Issue</strong><br/>'.wpautop(esc_html($details_issue)).'</div>';
    echo '<div class="be-qms-col-12"><strong>Summary of Action Taken</strong><br/>'.wpautop(esc_html($summary_action)).'</div>';
    echo '<div class="be-qms-col-12"><strong>Root Cause</strong><br/>'.wpautop(esc_html($root_cause)).'</div>';
    echo '<div class="be-qms-col-12"><strong>Prevent a recurrence</strong><br/>'.wpautop(esc_html($prevent_recurrence)).'</div>';
    echo '</div>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r02_capa'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R03 Purchase Order
  // -------------------------

  private static function render_r03_purchase_order_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'new'], self::portal_url()));
    $templates_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'templates'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R03 - Purchase Order</h3>'
      .'<div class="be-qms-muted">Purchase orders raised for goods-in checks.</div>'
      .'</div>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn" href="'.$add_url.'">Add New</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$templates_url.'">Existing Templates</a>'
      .'</div>';
    echo '</div>';

    $records = self::query_r03_purchase_orders();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Date Raised</th><th>PO Number</th><th>Supplier</th><th>Customer Ref</th><th>Options</th></tr></thead><tbody>';

    if (!$records) {
      echo '<tr><td colspan="5" class="be-qms-muted">No purchase orders yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($records as $record) {
        $rid = (int) $record->ID;
        $date_raised = get_post_meta($rid, '_be_qms_r03_date_raised', true);
        $po_number = get_post_meta($rid, '_be_qms_r03_po_number', true);
        $supplier = get_post_meta($rid, '_be_qms_r03_supplier_name', true);
        $customer_ref = get_post_meta($rid, '_be_qms_r03_customer_ref', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $uploads_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_date = $date_raised ? self::format_date_for_display($date_raised) : '—';

        echo '<tr>';
        echo '<td>'.esc_html($display_date).'</td>';
        echo '<td>'.esc_html($po_number ?: '—').'</td>';
        echo '<td>'.esc_html($supplier ?: '—').'</td>';
        echo '<td>'.esc_html($customer_ref ?: '—').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$uploads_url.'">Uploads</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this purchase order?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r03_purchase_orders() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r03_purchase_order'],
      ]],
      'meta_query' => [[
        'key' => '_be_qms_r03_is_template',
        'compare' => 'NOT EXISTS',
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function query_r03_templates() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r03_purchase_order'],
      ]],
      'meta_query' => [[
        'key' => '_be_qms_r03_is_template',
        'value' => '1',
        'compare' => '=',
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function fetch_r03_template_name($template_id) {
    $template_id = (int) $template_id;
    if (!$template_id) {
      return '';
    }
    $name = get_post_meta($template_id, '_be_qms_r03_template_name', true);
    if ($name) {
      return (string) $name;
    }
    $p = get_post($template_id);
    if ($p && $p->post_type === self::CPT_RECORD) {
      return (string) $p->post_title;
    }
    return '';
  }

  private static function render_r03_purchase_order_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $template_id = isset($_GET['template_id']) ? (int) $_GET['template_id'] : 0;
    $template = $template_id ? get_post($template_id) : null;
    $template_ok = $template && $template->post_type === self::CPT_RECORD;

    $po_number = $is_edit ? get_post_meta($id, '_be_qms_r03_po_number', true) : '';
    $customer_ref = $is_edit ? get_post_meta($id, '_be_qms_r03_customer_ref', true) : '';
    $supplier_id = $is_edit ? (int) get_post_meta($id, '_be_qms_r03_supplier_id', true) : 0;
    $supplier_name = $is_edit ? get_post_meta($id, '_be_qms_r03_supplier_name', true) : '';
    $description = $is_edit ? get_post_meta($id, '_be_qms_r03_description', true) : '';
    $raised_by = $is_edit ? get_post_meta($id, '_be_qms_r03_raised_by', true) : '';
    $date_raised = $is_edit ? get_post_meta($id, '_be_qms_r03_date_raised', true) : '';
    $inverter_model = $is_edit ? get_post_meta($id, '_be_qms_r03_inverter_model', true) : '';
    $solar_panels = $is_edit ? get_post_meta($id, '_be_qms_r03_solar_panels', true) : '';
    $solar_panels_qty = $is_edit ? get_post_meta($id, '_be_qms_r03_solar_panels_qty', true) : '';
    $battery_model = $is_edit ? get_post_meta($id, '_be_qms_r03_battery_model', true) : '';
    $other_equipment = $is_edit ? get_post_meta($id, '_be_qms_r03_other_equipment', true) : '';
    $linked_project = $is_edit ? (int) get_post_meta($id, self::META_PROJECT_LINK, true) : 0;
    $is_template = $is_edit ? get_post_meta($id, '_be_qms_r03_is_template', true) : '';
    $is_template_edit = $is_edit && $is_template;
    $template_name = '';
    if ($is_edit && $is_template) {
      $template_name = self::fetch_r03_template_name($id);
    }

    if (!$is_edit && $template_ok) {
      $po_number = get_post_meta($template_id, '_be_qms_r03_po_number', true);
      $customer_ref = get_post_meta($template_id, '_be_qms_r03_customer_ref', true);
      $supplier_id = (int) get_post_meta($template_id, '_be_qms_r03_supplier_id', true);
      $supplier_name = get_post_meta($template_id, '_be_qms_r03_supplier_name', true);
      $description = get_post_meta($template_id, '_be_qms_r03_description', true);
      $raised_by = get_post_meta($template_id, '_be_qms_r03_raised_by', true);
      $date_raised = get_post_meta($template_id, '_be_qms_r03_date_raised', true);
      $inverter_model = get_post_meta($template_id, '_be_qms_r03_inverter_model', true);
      $solar_panels = get_post_meta($template_id, '_be_qms_r03_solar_panels', true);
      $solar_panels_qty = get_post_meta($template_id, '_be_qms_r03_solar_panels_qty', true);
      $battery_model = get_post_meta($template_id, '_be_qms_r03_battery_model', true);
      $other_equipment = get_post_meta($template_id, '_be_qms_r03_other_equipment', true);
      $template_name = self::fetch_r03_template_name($template_id);
    }

    $suppliers = get_posts([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r08_approved_suppliers'],
      ]],
    ]);

    $projects = get_posts([
      'post_type' => self::CPT_PROJECT,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    $templates = self::query_r03_templates();

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R03 - Purchase Order</h3></div>';
    echo '</div>';

    if (!$is_edit && $templates) {
      echo '<form method="get" action="'.esc_url(self::portal_url()).'" style="margin-top:12px">';
      echo '<input type="hidden" name="view" value="records" />';
      echo '<input type="hidden" name="type" value="r03_purchase_order" />';
      echo '<input type="hidden" name="be_action" value="new" />';
      echo '<div class="be-qms-row">';
      echo '<label><strong>Load template</strong> ';
      echo '<select class="be-qms-select" name="template_id" style="min-width:240px">';
      echo '<option value="">Select a template</option>';
      foreach ($templates as $template) {
        $selected = ($template_id && $template_id === (int) $template->ID) ? 'selected' : '';
        echo '<option '.$selected.' value="'.esc_attr($template->ID).'">'.esc_html($template->post_title).'</option>';
      }
      echo '</select></label>';
      echo '<button class="be-qms-btn be-qms-btn-secondary" type="submit">Load</button>';
      echo '</div>';
      echo '</form>';
    }

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r03_purchase_order" />';
    if ($is_template_edit) {
      echo '<input type="hidden" name="r03_save_template" value="1" />';
    }
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Link PO Number</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_po_number" value="'.esc_attr($po_number).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Customer Reference</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_customer_ref" value="'.esc_attr($customer_ref).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Supplier</strong><br/>';
    echo '<select class="be-qms-select" name="r03_supplier_id">';
    if ($suppliers) {
      echo '<option value="">Select supplier</option>';
      foreach ($suppliers as $supplier) {
        $selected = ($supplier_id && (int) $supplier_id === (int) $supplier->ID) ? 'selected' : '';
        echo '<option '.$selected.' value="'.esc_attr($supplier->ID).'">'.esc_html($supplier->post_title).'</option>';
      }
    } else {
      echo '<option value="">No approved suppliers yet — create one in R08.</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Description</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r03_description">'.esc_textarea($description).'</textarea></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Raised By</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_raised_by" value="'.esc_attr($raised_by).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date Raised</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r03_date_raised" value="'.esc_attr(self::format_date_for_display($date_raised)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-12"><hr style="margin:8px 0;border:0;border-top:1px solid rgba(30,41,59,.75)"></div>';
    echo '<div class="be-qms-col-12"><strong>Equipment list</strong></div>';

    echo '<div class="be-qms-col-6"><label><strong>Inverter model</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_inverter_model" value="'.esc_attr($inverter_model).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Solar panels</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_solar_panels" value="'.esc_attr($solar_panels).'" placeholder="e.g. 410W panel model" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Solar panels (qty)</strong><br/>';
    echo '<input class="be-qms-input" type="number" min="0" name="r03_solar_panels_qty" value="'.esc_attr($solar_panels_qty).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Battery model</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r03_battery_model" value="'.esc_attr($battery_model).'" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Other equipment</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r03_other_equipment">'.esc_textarea($other_equipment).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Linked project</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<select class="be-qms-select" name="project_id">';
    echo '<option value="0">— Company record (not tied to a job) —</option>';
    if (!empty($projects)) {
      foreach ($projects as $project) {
        $selected = ($linked_project && (int) $linked_project === (int) $project->ID) ? 'selected' : '';
        echo '<option '.$selected.' value="'.esc_attr($project->ID).'">'.esc_html($project->post_title).'</option>';
      }
    }
    echo '</select></label></div>';

    if (!$is_template_edit) {
      $template_checked = $is_template ? 'checked' : '';
      $template_disabled = $is_template ? '' : 'disabled';
      echo '<div class="be-qms-col-12"><label><input type="checkbox" name="r03_save_template" value="1" '.$template_checked.' /> Save this purchase order as a template</label></div>';
      echo '<div class="be-qms-col-12"><label><strong>Template name</strong> <span class="be-qms-muted">(used when saving as a template)</span><br/>';
      echo '<input class="be-qms-input" type="text" name="r03_template_name" value="'.esc_attr($template_name).'" placeholder="e.g. Morgan Sindall kit list" '.$template_disabled.' /></label></div>';
      echo '<input type="hidden" name="r03_template_toggle" value="1" />';
    } else {
      echo '<div class="be-qms-col-12 be-qms-muted">Template name can only be edited from the “Purchase Order Templates” list.</div>';
      echo '<input type="hidden" name="r03_template_toggle" value="0" />';
    }

    echo '</div>';

    if (!$is_edit) {
      echo '<div class="be-qms-muted" style="margin-top:10px">Uploads will appear once the record is saved.</div>';
    }

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit" name="save_action" value="stay">Save</button>';
    echo '<button class="be-qms-btn be-qms-btn-secondary" type="submit" name="save_action" value="close">Save &amp; Close</button>';
    if ($is_edit) {
      $uploads_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$id], self::portal_url()));
      echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$uploads_url.'">Uploads</a>';
    }
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r03_purchase_order_templates() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'new'], self::portal_url()));
    $templates = self::query_r03_templates();

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R03 - Purchase Order Templates</h3>'
      .'<div class="be-qms-muted">Select a saved template to prefill a new purchase order.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Create New</a></div>';
    echo '</div>';

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Template</th><th>Supplier</th><th>Created</th><th>Options</th></tr></thead><tbody>';

    if (!$templates) {
      echo '<tr><td colspan="4" class="be-qms-muted">No templates saved yet. Create a purchase order and tick “Save this purchase order as a template”.</td></tr>';
    } else {
      foreach ($templates as $template) {
        $rid = (int) $template->ID;
        $supplier = get_post_meta($rid, '_be_qms_r03_supplier_name', true);
        $created = get_the_date('Y-m-d', $rid);
        $use_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'new','template_id'=>$rid], self::portal_url()));
        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_created = $created ? self::format_date_for_display($created) : '—';

        echo '<tr>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html($template->post_title).'</a></td>';
        echo '<td>'.esc_html($supplier ?: '—').'</td>';
        echo '<td>'.esc_html($display_created).'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$use_url.'">Use Template</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this template?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Purchase Orders</a></div>';
  }

  private static function render_r03_purchase_order_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $po_number = get_post_meta($id, '_be_qms_r03_po_number', true);
    $customer_ref = get_post_meta($id, '_be_qms_r03_customer_ref', true);
    $supplier = get_post_meta($id, '_be_qms_r03_supplier_name', true);
    $description = get_post_meta($id, '_be_qms_r03_description', true);
    $raised_by = get_post_meta($id, '_be_qms_r03_raised_by', true);
    $date_raised = get_post_meta($id, '_be_qms_r03_date_raised', true);
    $inverter_model = get_post_meta($id, '_be_qms_r03_inverter_model', true);
    $solar_panels = get_post_meta($id, '_be_qms_r03_solar_panels', true);
    $solar_panels_qty = get_post_meta($id, '_be_qms_r03_solar_panels_qty', true);
    $battery_model = get_post_meta($id, '_be_qms_r03_battery_model', true);
    $other_equipment = get_post_meta($id, '_be_qms_r03_other_equipment', true);

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'edit','id'=>$id], self::portal_url()));
    $uploads_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R03 - Purchase Order</h3>';
    echo '<div class="be-qms-muted">'.esc_html(self::format_date_for_display($date_raised) ?: '—').'</div></div>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$uploads_url.'">Uploads</a>'
      .'</div>';
    echo '</div>';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>PO Number</th><td>'.esc_html($po_number ?: '—').'</td></tr>';
    echo '<tr><th>Customer Reference</th><td>'.esc_html($customer_ref ?: '—').'</td></tr>';
    echo '<tr><th>Supplier</th><td>'.esc_html($supplier ?: '—').'</td></tr>';
    echo '<tr><th>Raised By</th><td>'.esc_html($raised_by ?: '—').'</td></tr>';
    echo '<tr><th>Date Raised</th><td>'.esc_html(self::format_date_for_display($date_raised) ?: '—').'</td></tr>';
    echo '<tr><th>Description</th><td>'.wpautop(esc_html($description)).'</td></tr>';
    echo '<tr><th>Inverter model</th><td>'.esc_html($inverter_model ?: '—').'</td></tr>';
    echo '<tr><th>Solar panels</th><td>'.esc_html($solar_panels ?: '—').'</td></tr>';
    echo '<tr><th>Solar panels (qty)</th><td>'.esc_html($solar_panels_qty ?: '—').'</td></tr>';
    echo '<tr><th>Battery model</th><td>'.esc_html($battery_model ?: '—').'</td></tr>';
    echo '<tr><th>Other equipment</th><td>'.wpautop(esc_html($other_equipment)).'</td></tr>';
    echo '</table>';
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  private static function render_r03_purchase_order_uploads($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $doc_ids = get_post_meta($id, '_be_qms_r03_documents', true);
    if (!is_array($doc_ids)) $doc_ids = [];
    $photo_ids = get_post_meta($id, '_be_qms_r03_photos', true);
    if (!is_array($photo_ids)) $photo_ids = [];

    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'add_upload','id'=>$id], self::portal_url()));
    $back_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R03 - Purchase Order</h3><div class="be-qms-muted">Documents</div></div>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn" href="'.$add_url.'">Add File</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$back_url.'">Return to Record</a>'
      .'</div>';
    echo '</div>';

    echo '<h4 style="margin-top:16px">Documents</h4>';
    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>File</th><th>Date Uploaded</th><th>Options</th></tr></thead><tbody>';
    if (!$doc_ids) {
      echo '<tr><td colspan="3" class="be-qms-muted">No files have been uploaded in this section.</td></tr>';
    } else {
      foreach ($doc_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid) ?: basename((string) $url);
        $date = get_the_date('Y-m-d', $aid);
        $remove_url = esc_url(admin_url('admin-post.php?action=be_qms_remove_r03_upload&id='.$id.'&attachment_id='.$aid.'&upload_type=document&_wpnonce='.wp_create_nonce('be_qms_remove_r03_upload_'.$id)));
        echo '<tr>';
        echo '<td>'.($url ? '<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name).'</a>' : esc_html($name)).'</td>';
        echo '<td>'.esc_html(self::format_date_for_display($date)).'</td>';
        echo '<td class="be-qms-row"><a class="be-qms-btn be-qms-btn-danger" href="'.$remove_url.'" onclick="return confirm(\'Remove this file?\')">Remove</a></td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';

    echo '<h4 style="margin-top:20px">Photos</h4>';
    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>File</th><th>Date Uploaded</th><th>Options</th></tr></thead><tbody>';
    if (!$photo_ids) {
      echo '<tr><td colspan="3" class="be-qms-muted">No files have been uploaded in this section.</td></tr>';
    } else {
      foreach ($photo_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid) ?: basename((string) $url);
        $date = get_the_date('Y-m-d', $aid);
        $remove_url = esc_url(admin_url('admin-post.php?action=be_qms_remove_r03_upload&id='.$id.'&attachment_id='.$aid.'&upload_type=image&_wpnonce='.wp_create_nonce('be_qms_remove_r03_upload_'.$id)));
        echo '<tr>';
        echo '<td>'.($url ? '<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name).'</a>' : esc_html($name)).'</td>';
        echo '<td>'.esc_html(self::format_date_for_display($date)).'</td>';
        echo '<td class="be-qms-row"><a class="be-qms-btn be-qms-btn-danger" href="'.$remove_url.'" onclick="return confirm(\'Remove this file?\')">Remove</a></td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
  }

  private static function render_r03_purchase_order_upload_form($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Record not found.</div>';
      return;
    }

    $upload_url = esc_url(admin_url('admin-post.php'));
    $back_url = esc_url(add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R03 - Purchase Order</h3></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back_url.'">Return to Uploads</a></div>';
    echo '</div>';

    echo '<form method="post" action="'.$upload_url.'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_r03_upload" />';
    echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    wp_nonce_field('be_qms_save_r03_upload');

    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-6"><label><strong>Upload Type</strong><br/>';
    echo '<select class="be-qms-select" name="upload_type" required>';
    echo '<option value="">Please Select</option>';
    echo '<option value="document">Document</option>';
    echo '<option value="image">Image</option>';
    echo '</select></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Upload File</strong><br/>';
    echo '<input type="file" name="r03_upload" required /></label></div>';
    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">Upload</button>';
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back_url.'">Return to Uploads</a>';
    echo '</div>';
    echo '</form>';
  }

  // -------------------------
  // R08 Approved Suppliers
  // -------------------------

  private static function render_r08_supplier_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R08 - Approved Suppliers</h3>'
      .'<div class="be-qms-muted">Maintain the list of approved suppliers used for procurement.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $suppliers = self::query_r08_suppliers();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Supplier</th><th>Services / Products</th><th>Approved</th><th>Review Date</th><th>Status</th><th>Options</th></tr></thead><tbody>';

    if (!$suppliers) {
      echo '<tr><td colspan="6" class="be-qms-muted">No approved suppliers yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($suppliers as $supplier) {
        $rid = (int) $supplier->ID;
        $name = get_post_meta($rid, '_be_qms_r08_supplier_name', true) ?: $supplier->post_title;
        $services = get_post_meta($rid, '_be_qms_r08_services', true);
        $approved_date = get_post_meta($rid, '_be_qms_r08_approval_date', true);
        $review_date = get_post_meta($rid, '_be_qms_r08_review_date', true);
        $status = get_post_meta($rid, '_be_qms_r08_status', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_approved = $approved_date ? self::format_date_for_display($approved_date) : '—';
        $display_review = $review_date ? self::format_date_for_display($review_date) : '—';

        echo '<tr>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html($name ?: '—').'</a></td>';
        echo '<td>'.esc_html($services ?: '—').'</td>';
        echo '<td>'.esc_html($display_approved).'</td>';
        echo '<td>'.esc_html($display_review).'</td>';
        echo '<td>'.esc_html($status ?: '—').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this supplier?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r08_suppliers() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r08_approved_suppliers'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r08_supplier_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Supplier record not found.</div>';
      return;
    }

    $name = $is_edit ? (get_post_meta($id, '_be_qms_r08_supplier_name', true) ?: $p->post_title) : '';
    $services = $is_edit ? get_post_meta($id, '_be_qms_r08_services', true) : '';
    $contact_name = $is_edit ? get_post_meta($id, '_be_qms_r08_contact_name', true) : '';
    $contact_email = $is_edit ? get_post_meta($id, '_be_qms_r08_contact_email', true) : '';
    $contact_phone = $is_edit ? get_post_meta($id, '_be_qms_r08_contact_phone', true) : '';
    $address = $is_edit ? get_post_meta($id, '_be_qms_r08_address', true) : '';
    $approved_by = $is_edit ? get_post_meta($id, '_be_qms_r08_approved_by', true) : '';
    $approval_date = $is_edit ? get_post_meta($id, '_be_qms_r08_approval_date', true) : '';
    $review_date = $is_edit ? get_post_meta($id, '_be_qms_r08_review_date', true) : '';
    $status = $is_edit ? get_post_meta($id, '_be_qms_r08_status', true) : '';
    $notes = $is_edit ? get_post_meta($id, '_be_qms_r08_notes', true) : '';
    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R08 - Approved Suppliers</h3>';
    echo '<div class="be-qms-muted">'.($is_edit ? 'Edit supplier record' : 'Add new supplier').'</div></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r08_approved_suppliers" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Supplier name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r08_supplier_name" value="'.esc_attr($name).'" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Services / Products</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r08_services" value="'.esc_attr($services).'" placeholder="e.g. PV modules, inverters" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r08_contact_name" value="'.esc_attr($contact_name).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact email</strong><br/>';
    echo '<input class="be-qms-input" type="email" name="r08_contact_email" value="'.esc_attr($contact_email).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact phone</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r08_contact_phone" value="'.esc_attr($contact_phone).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Approved by</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r08_approved_by" value="'.esc_attr($approved_by).'" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Address</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r08_address">'.esc_textarea($address).'</textarea></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Approval date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r08_approval_date" value="'.esc_attr(self::format_date_for_display($approval_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Review date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r08_review_date" value="'.esc_attr(self::format_date_for_display($review_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Status</strong><br/>';
    echo '<select class="be-qms-select" name="r08_status">';
    $status_options = ['Approved', 'Conditional', 'Suspended'];
    echo '<option value="">— Select —</option>';
    foreach ($status_options as $option) {
      $selected = ($status === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Notes</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r08_notes">'.esc_textarea($notes).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attachments</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r08_supplier_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Supplier record not found.</div>';
      return;
    }

    $name = get_post_meta($id, '_be_qms_r08_supplier_name', true) ?: $p->post_title;
    $services = get_post_meta($id, '_be_qms_r08_services', true);
    $contact_name = get_post_meta($id, '_be_qms_r08_contact_name', true);
    $contact_email = get_post_meta($id, '_be_qms_r08_contact_email', true);
    $contact_phone = get_post_meta($id, '_be_qms_r08_contact_phone', true);
    $address = get_post_meta($id, '_be_qms_r08_address', true);
    $approved_by = get_post_meta($id, '_be_qms_r08_approved_by', true);
    $approval_date = get_post_meta($id, '_be_qms_r08_approval_date', true);
    $review_date = get_post_meta($id, '_be_qms_r08_review_date', true);
    $status = get_post_meta($id, '_be_qms_r08_status', true);
    $notes = get_post_meta($id, '_be_qms_r08_notes', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R08 - Approved Suppliers</h3>';
    echo '<div class="be-qms-muted">'.esc_html($name ?: '—').'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Supplier</th><td>'.esc_html($name ?: '—').'</td></tr>';
    echo '<tr><th>Services / Products</th><td>'.esc_html($services ?: '—').'</td></tr>';
    echo '<tr><th>Contact name</th><td>'.esc_html($contact_name ?: '—').'</td></tr>';
    echo '<tr><th>Contact email</th><td>'.esc_html($contact_email ?: '—').'</td></tr>';
    echo '<tr><th>Contact phone</th><td>'.esc_html($contact_phone ?: '—').'</td></tr>';
    echo '<tr><th>Address</th><td>'.wpautop(esc_html($address ?: '—')).'</td></tr>';
    echo '<tr><th>Approved by</th><td>'.esc_html($approved_by ?: '—').'</td></tr>';
    $display_approval = $approval_date ? self::format_date_for_display($approval_date) : '—';
    $display_review = $review_date ? self::format_date_for_display($review_date) : '—';
    echo '<tr><th>Approval date</th><td>'.esc_html($display_approval).'</td></tr>';
    echo '<tr><th>Review date</th><td>'.esc_html($display_review).'</td></tr>';
    echo '<tr><th>Status</th><td>'.esc_html($status ?: '—').'</td></tr>';
    echo '<tr><th>Notes</th><td>'.wpautop(esc_html($notes ?: '—')).'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r08_approved_suppliers'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R09 Approved Subcontractors
  // -------------------------

  private static function render_r09_subcontractor_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R09 - Approved Subcontractors</h3>'
      .'<div class="be-qms-muted">Maintain the list of approved subcontractors and link to R07 training.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $subs = self::query_r09_subcontractors();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Subcontractor</th><th>Scope / Services</th><th>Review Date</th><th>Status</th><th>R07 Training</th><th>Options</th></tr></thead><tbody>';

    if (!$subs) {
      echo '<tr><td colspan="6" class="be-qms-muted">No subcontractors yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($subs as $sub) {
        $rid = (int) $sub->ID;
        $name = get_post_meta($rid, '_be_qms_r09_subcontractor_name', true) ?: $sub->post_title;
        $services = get_post_meta($rid, '_be_qms_r09_services', true);
        $review_date = get_post_meta($rid, '_be_qms_r09_review_date', true);
        $status = get_post_meta($rid, '_be_qms_r09_status', true);
        $employee_id = (int) get_post_meta($rid, '_be_qms_r09_employee_id', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_review = $review_date ? self::format_date_for_display($review_date) : '—';

        $training_link = '—';
        if ($employee_id) {
          $employee_name = get_the_title($employee_id);
          if ($employee_name) {
            $employee_url = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$employee_id], self::portal_url()));
            $training_link = '<a class="be-qms-link" href="'.$employee_url.'">'.esc_html($employee_name).'</a>';
          }
        }

        echo '<tr>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html($name ?: '—').'</a></td>';
        echo '<td>'.esc_html($services ?: '—').'</td>';
        echo '<td>'.esc_html($display_review).'</td>';
        echo '<td>'.esc_html($status ?: '—').'</td>';
        echo '<td>'.$training_link.'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this subcontractor?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r09_subcontractors() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r09_approved_subcontract'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r09_subcontractor_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Subcontractor record not found.</div>';
      return;
    }

    $name = $is_edit ? (get_post_meta($id, '_be_qms_r09_subcontractor_name', true) ?: $p->post_title) : '';
    $services = $is_edit ? get_post_meta($id, '_be_qms_r09_services', true) : '';
    $contact_name = $is_edit ? get_post_meta($id, '_be_qms_r09_contact_name', true) : '';
    $contact_email = $is_edit ? get_post_meta($id, '_be_qms_r09_contact_email', true) : '';
    $contact_phone = $is_edit ? get_post_meta($id, '_be_qms_r09_contact_phone', true) : '';
    $address = $is_edit ? get_post_meta($id, '_be_qms_r09_address', true) : '';
    $approval_date = $is_edit ? get_post_meta($id, '_be_qms_r09_approval_date', true) : '';
    $review_date = $is_edit ? get_post_meta($id, '_be_qms_r09_review_date', true) : '';
    $status = $is_edit ? get_post_meta($id, '_be_qms_r09_status', true) : '';
    $employee_id = $is_edit ? (int) get_post_meta($id, '_be_qms_r09_employee_id', true) : 0;
    $notes = $is_edit ? get_post_meta($id, '_be_qms_r09_notes', true) : '';
    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];

    $employees = get_posts([
      'post_type' => self::CPT_EMPLOYEE,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R09 - Approved Subcontractors</h3>';
    echo '<div class="be-qms-muted">'.($is_edit ? 'Edit subcontractor record' : 'Add new subcontractor').'</div></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r09_approved_subcontract" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Subcontractor name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r09_subcontractor_name" value="'.esc_attr($name).'" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Scope / Services</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r09_services" value="'.esc_attr($services).'" placeholder="e.g. electrical install, scaffold" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r09_contact_name" value="'.esc_attr($contact_name).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact email</strong><br/>';
    echo '<input class="be-qms-input" type="email" name="r09_contact_email" value="'.esc_attr($contact_email).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Contact phone</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r09_contact_phone" value="'.esc_attr($contact_phone).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Approval date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r09_approval_date" value="'.esc_attr(self::format_date_for_display($approval_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Address</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r09_address">'.esc_textarea($address).'</textarea></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Review date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r09_review_date" value="'.esc_attr(self::format_date_for_display($review_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Status</strong><br/>';
    echo '<select class="be-qms-select" name="r09_status">';
    $status_options = ['Approved', 'Conditional', 'Suspended'];
    echo '<option value="">— Select —</option>';
    foreach ($status_options as $option) {
      $selected = ($status === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>R07 Training link</strong><br/>';
    echo '<select class="be-qms-select" name="r09_employee_id">';
    echo '<option value="0">— Not linked —</option>';
    if ($employees) {
      foreach ($employees as $employee) {
        $selected = ($employee_id && (int) $employee_id === (int) $employee->ID) ? 'selected' : '';
        echo '<option '.$selected.' value="'.esc_attr($employee->ID).'">'.esc_html($employee->post_title).'</option>';
      }
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Notes</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r09_notes">'.esc_textarea($notes).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attachments</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r09_subcontractor_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Subcontractor record not found.</div>';
      return;
    }

    $name = get_post_meta($id, '_be_qms_r09_subcontractor_name', true) ?: $p->post_title;
    $services = get_post_meta($id, '_be_qms_r09_services', true);
    $contact_name = get_post_meta($id, '_be_qms_r09_contact_name', true);
    $contact_email = get_post_meta($id, '_be_qms_r09_contact_email', true);
    $contact_phone = get_post_meta($id, '_be_qms_r09_contact_phone', true);
    $address = get_post_meta($id, '_be_qms_r09_address', true);
    $approval_date = get_post_meta($id, '_be_qms_r09_approval_date', true);
    $review_date = get_post_meta($id, '_be_qms_r09_review_date', true);
    $status = get_post_meta($id, '_be_qms_r09_status', true);
    $employee_id = (int) get_post_meta($id, '_be_qms_r09_employee_id', true);
    $notes = get_post_meta($id, '_be_qms_r09_notes', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R09 - Approved Subcontractors</h3>';
    echo '<div class="be-qms-muted">'.esc_html($name ?: '—').'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    $training_link = '—';
    if ($employee_id) {
      $employee_name = get_the_title($employee_id);
      if ($employee_name) {
        $employee_url = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$employee_id], self::portal_url()));
        $training_link = '<a class="be-qms-link" href="'.$employee_url.'">'.esc_html($employee_name).'</a>';
      }
    }

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Subcontractor</th><td>'.esc_html($name ?: '—').'</td></tr>';
    echo '<tr><th>Scope / Services</th><td>'.esc_html($services ?: '—').'</td></tr>';
    echo '<tr><th>Contact name</th><td>'.esc_html($contact_name ?: '—').'</td></tr>';
    echo '<tr><th>Contact email</th><td>'.esc_html($contact_email ?: '—').'</td></tr>';
    echo '<tr><th>Contact phone</th><td>'.esc_html($contact_phone ?: '—').'</td></tr>';
    echo '<tr><th>Address</th><td>'.wpautop(esc_html($address ?: '—')).'</td></tr>';
    $display_approval = $approval_date ? self::format_date_for_display($approval_date) : '—';
    $display_review = $review_date ? self::format_date_for_display($review_date) : '—';
    echo '<tr><th>Approval date</th><td>'.esc_html($display_approval).'</td></tr>';
    echo '<tr><th>Review date</th><td>'.esc_html($display_review).'</td></tr>';
    echo '<tr><th>Status</th><td>'.esc_html($status ?: '—').'</td></tr>';
    echo '<tr><th>R07 Training</th><td>'.$training_link.'</td></tr>';
    echo '<tr><th>Notes</th><td>'.wpautop(esc_html($notes ?: '—')).'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r09_approved_subcontract'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R11 Company Documents
  // -------------------------

  private static function render_r11_company_documents_list() {
    $add_url = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents','be_action'=>'new'], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">R11 - Company Documents</h3>'
      .'<div class="be-qms-muted">Track controlled company documents, software, and data storage references.</div>'
      .'</div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn" href="'.$add_url.'">Add New</a></div>';
    echo '</div>';

    $docs = self::query_r11_company_documents();

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Document</th><th>Category</th><th>Version</th><th>Review Date</th><th>Status</th><th>Options</th></tr></thead><tbody>';

    if (!$docs) {
      echo '<tr><td colspan="6" class="be-qms-muted">No company documents yet. Click “Add New”.</td></tr>';
    } else {
      foreach ($docs as $doc) {
        $rid = (int) $doc->ID;
        $title = get_post_meta($rid, '_be_qms_r11_doc_title', true) ?: $doc->post_title;
        $category = get_post_meta($rid, '_be_qms_r11_category', true);
        $version = get_post_meta($rid, '_be_qms_r11_version', true);
        $review_date = get_post_meta($rid, '_be_qms_r11_review_date', true);
        $status = get_post_meta($rid, '_be_qms_r11_status', true);

        $view_url = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents','be_action'=>'view','id'=>$rid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents','be_action'=>'edit','id'=>$rid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=record&id='.$rid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$rid)));

        $display_review = $review_date ? self::format_date_for_display($review_date) : '—';

        echo '<tr>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html($title ?: '—').'</a></td>';
        echo '<td>'.esc_html($category ?: '—').'</td>';
        echo '<td>'.esc_html($version ?: '—').'</td>';
        echo '<td>'.esc_html($display_review).'</td>';
        echo '<td>'.esc_html($status ?: '—').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Remove this document?\')">Remove</a>'
          .'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function query_r11_company_documents() {
    $q = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'tax_query' => [[
        'taxonomy' => self::TAX_RECORD_TYPE,
        'field' => 'slug',
        'terms' => ['r11_company_documents'],
      ]],
    ]);
    return $q->have_posts() ? $q->posts : [];
  }

  private static function render_r11_company_documents_form($id) {
    $is_edit = $id > 0;
    $p = $is_edit ? get_post($id) : null;
    if ($is_edit && (!$p || $p->post_type !== self::CPT_RECORD)) {
      echo '<div class="be-qms-muted">Document record not found.</div>';
      return;
    }

    $doc_title = $is_edit ? (get_post_meta($id, '_be_qms_r11_doc_title', true) ?: $p->post_title) : '';
    $category = $is_edit ? get_post_meta($id, '_be_qms_r11_category', true) : '';
    $reference = $is_edit ? get_post_meta($id, '_be_qms_r11_reference', true) : '';
    $version = $is_edit ? get_post_meta($id, '_be_qms_r11_version', true) : '';
    $owner = $is_edit ? get_post_meta($id, '_be_qms_r11_owner', true) : '';
    $location = $is_edit ? get_post_meta($id, '_be_qms_r11_location', true) : '';
    $link = $is_edit ? get_post_meta($id, '_be_qms_r11_link', true) : '';
    $issue_date = $is_edit ? get_post_meta($id, '_be_qms_r11_issue_date', true) : '';
    $review_date = $is_edit ? get_post_meta($id, '_be_qms_r11_review_date', true) : '';
    $status = $is_edit ? get_post_meta($id, '_be_qms_r11_status', true) : '';
    $notes = $is_edit ? get_post_meta($id, '_be_qms_r11_notes', true) : '';
    $existing_att_ids = $is_edit ? get_post_meta($id, '_be_qms_attachments', true) : [];
    if (!is_array($existing_att_ids)) $existing_att_ids = [];

    $categories = [
      'External Docs',
      'Accessed Docs',
      'Live Company Docs',
      'Old Company Docs',
      'Software',
      'Data Storage',
      'Backup Info',
    ];

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R11 - Company Documents</h3>';
    echo '<div class="be-qms-muted">'.($is_edit ? 'Edit document record' : 'Add new document').'</div></div>';
    echo '</div>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-top:12px">';
    echo '<input type="hidden" name="action" value="be_qms_save_record" />';
    echo '<input type="hidden" name="record_type" value="r11_company_documents" />';
    wp_nonce_field('be_qms_save_record');
    if ($is_edit) {
      echo '<input type="hidden" name="record_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-6"><label><strong>Document title</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r11_doc_title" value="'.esc_attr($doc_title).'" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Category</strong><br/>';
    echo '<select class="be-qms-select" name="r11_category">';
    echo '<option value="">— Select —</option>';
    foreach ($categories as $option) {
      $selected = ($category === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Reference / ID</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r11_reference" value="'.esc_attr($reference).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Version</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r11_version" value="'.esc_attr($version).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Owner</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r11_owner" value="'.esc_attr($owner).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Location / Storage</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="r11_location" value="'.esc_attr($location).'" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Document link (optional)</strong><br/>';
    echo '<input class="be-qms-input" type="url" name="r11_link" value="'.esc_attr($link).'" placeholder="https://..." /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Issue date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r11_issue_date" value="'.esc_attr(self::format_date_for_display($issue_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Review date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="r11_review_date" value="'.esc_attr(self::format_date_for_display($review_date)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-4"><label><strong>Status</strong><br/>';
    echo '<select class="be-qms-select" name="r11_status">';
    $status_options = ['Active', 'Superseded', 'Draft'];
    echo '<option value="">— Select —</option>';
    foreach ($status_options as $option) {
      $selected = ($status === $option) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($option).'">'.esc_html($option).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Notes</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="r11_notes">'.esc_textarea($notes).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attachments</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';

    if ($is_edit) {
      echo '<div class="be-qms-col-12"><strong>Existing attachments</strong><br/>';
      if (!$existing_att_ids) {
        echo '<div class="be-qms-muted">None.</div>';
      } else {
        echo '<div class="be-qms-muted">Tick to remove on save:</div>';
        echo '<ul style="margin:8px 0 0 18px">';
        foreach ($existing_att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          if (!$url) continue;
          echo '<li><label style="display:flex;gap:10px;align-items:center">'
            .'<input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'">'
            .'<a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a>'
            .'</label></li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save').'</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_r11_company_documents_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_RECORD) {
      echo '<div class="be-qms-muted">Document record not found.</div>';
      return;
    }

    $doc_title = get_post_meta($id, '_be_qms_r11_doc_title', true) ?: $p->post_title;
    $category = get_post_meta($id, '_be_qms_r11_category', true);
    $reference = get_post_meta($id, '_be_qms_r11_reference', true);
    $version = get_post_meta($id, '_be_qms_r11_version', true);
    $owner = get_post_meta($id, '_be_qms_r11_owner', true);
    $location = get_post_meta($id, '_be_qms_r11_location', true);
    $link = get_post_meta($id, '_be_qms_r11_link', true);
    $issue_date = get_post_meta($id, '_be_qms_r11_issue_date', true);
    $review_date = get_post_meta($id, '_be_qms_r11_review_date', true);
    $status = get_post_meta($id, '_be_qms_r11_status', true);
    $notes = get_post_meta($id, '_be_qms_r11_notes', true);
    $att_ids = get_post_meta($id, '_be_qms_attachments', true);
    if (!is_array($att_ids)) $att_ids = [];

    $edit_url = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents','be_action'=>'edit','id'=>$id], self::portal_url()));

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R11 - Company Documents</h3>';
    echo '<div class="be-qms-muted">'.esc_html($doc_title ?: '—').'</div></div>';
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a></div>';
    echo '</div>';

    $display_issue = $issue_date ? self::format_date_for_display($issue_date) : '—';
    $display_review = $review_date ? self::format_date_for_display($review_date) : '—';

    echo '<div class="be-qms-card" style="margin-top:14px">';
    echo '<table class="be-qms-table">';
    echo '<tr><th>Document</th><td>'.esc_html($doc_title ?: '—').'</td></tr>';
    echo '<tr><th>Category</th><td>'.esc_html($category ?: '—').'</td></tr>';
    echo '<tr><th>Reference / ID</th><td>'.esc_html($reference ?: '—').'</td></tr>';
    echo '<tr><th>Version</th><td>'.esc_html($version ?: '—').'</td></tr>';
    echo '<tr><th>Owner</th><td>'.esc_html($owner ?: '—').'</td></tr>';
    echo '<tr><th>Location / Storage</th><td>'.esc_html($location ?: '—').'</td></tr>';
    if ($link) {
      echo '<tr><th>Document link</th><td><a class="be-qms-link" target="_blank" href="'.esc_url($link).'">'.esc_html($link).'</a></td></tr>';
    } else {
      echo '<tr><th>Document link</th><td>—</td></tr>';
    }
    echo '<tr><th>Issue date</th><td>'.esc_html($display_issue).'</td></tr>';
    echo '<tr><th>Review date</th><td>'.esc_html($display_review).'</td></tr>';
    echo '<tr><th>Status</th><td>'.esc_html($status ?: '—').'</td></tr>';
    echo '<tr><th>Notes</th><td>'.wpautop(esc_html($notes ?: '—')).'</td></tr>';
    echo '</table>';

    echo '<h4 style="margin-top:14px">Attachments</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }
    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r11_company_documents'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Records</a></div>';
  }

  // -------------------------
  // R07 Training Matrix
  // -------------------------

  private static function render_r07_training_matrix($action) {
    // Routes inside R07
    $sub = $action;
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $emp = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;

    if ($sub === 'new_employee') {
      self::render_r07_employee_form(0);
      return;
    }
    if ($sub === 'edit_employee' && $id) {
      self::render_r07_employee_form($id);
      return;
    }
    if ($sub === 'employee' && $id) {
      self::render_r07_employee_view($id);
      return;
    }
    if ($sub === 'add_skill' && $emp) {
      self::render_r07_skill_form($emp, 0);
      return;
    }
    if ($sub === 'edit_skill' && $id && $emp) {
      self::render_r07_skill_form($emp, $id);
      return;
    }

    // Default: list employees
    self::render_r07_employee_list();
  }

  private static function render_r07_employee_list() {
    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div>'
      .'<h3 style="margin:0">Personal Skills &amp; Training Matrix</h3>'
      .'<div class="be-qms-muted">Your existing employees/courses are displayed below. Click “View” to manage their training and skills.</div>'
      .'</div>';
    $add = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'new_employee'], self::portal_url()));
    $print_url = esc_url(add_query_arg(['be_qms_export'=>'print_r07'], self::portal_url()));
    $back_url = esc_url(add_query_arg(['view'=>'records'], self::portal_url()));
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn" href="'.$add.'">Add New</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print Log</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$back_url.'">Return to Records</a>'
      .'</div>';
    echo '</div>';

    $skills = get_posts([
      'post_type' => self::CPT_TRAINING,
      'post_status' => 'publish',
      'numberposts' => 500,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    if ($skills) {
      $employee_titles = [];
      foreach ($skills as $skill) {
        $eid = (int) get_post_meta($skill->ID, self::META_EMPLOYEE_LINK, true);
        if ($eid && !isset($employee_titles[$eid])) {
          $employee_titles[$eid] = get_the_title($eid);
        }
      }

      usort($skills, function($a, $b) use ($employee_titles) {
        $a_emp = (int) get_post_meta($a->ID, self::META_EMPLOYEE_LINK, true);
        $b_emp = (int) get_post_meta($b->ID, self::META_EMPLOYEE_LINK, true);
        $a_name = $employee_titles[$a_emp] ?? '';
        $b_name = $employee_titles[$b_emp] ?? '';
        $cmp = strcasecmp($a_name, $b_name);
        if ($cmp !== 0) {
          return $cmp;
        }
        $a_course = get_post_meta($a->ID, '_be_qms_training_course', true) ?: $a->post_title;
        $b_course = get_post_meta($b->ID, '_be_qms_training_course', true) ?: $b->post_title;
        return strcasecmp($a_course, $b_course);
      });
    }

    echo '<table class="be-qms-table" style="margin-top:12px">';
    echo '<thead><tr><th>Employee Name</th><th>Course Name</th><th>Renewal Date</th><th>Options</th></tr></thead><tbody>';

    if (!$skills) {
      echo '<tr><td colspan="4" class="be-qms-muted">No training records yet. Click “Add New” to create an employee first.</td></tr>';
    } else {
      $last_emp = 0;
      foreach ($skills as $skill) {
        $sid = (int) $skill->ID;
        $eid = (int) get_post_meta($sid, self::META_EMPLOYEE_LINK, true);
        $emp_name = $eid ? get_the_title($eid) : '';
        $course = get_post_meta($sid, '_be_qms_training_course', true) ?: $skill->post_title;
        $renew = get_post_meta($sid, '_be_qms_training_renewal', true);

        $emp_cell = '';
        $emp_view = '';
        $emp_del = '';
        if ($eid && $eid !== $last_emp) {
          $emp_view = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$eid], self::portal_url()));
          $emp_del  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=employee&id='.$eid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$eid)));
          $emp_cell = '<a class="be-qms-link" href="'.$emp_view.'">'.esc_html($emp_name).'</a>'
            .'<div class="be-qms-muted" style="margin-top:6px">'
            .'<a class="be-qms-link" href="'.$emp_del.'" style="color:#fecaca" onclick="return confirm(\'Delete this employee and their training records?\')">Remove</a>'
            .'</div>';
        }

        $skill_del  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=training&id='.$sid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$sid)));

        $display_renew = $renew ? self::format_date_for_display($renew) : '—';

        echo '<tr>';
        echo '<td>'.($emp_cell ?: '<span class="be-qms-muted">—</span>').'</td>';
        echo '<td>'.esc_html($course).'</td>';
        echo '<td>'.esc_html($display_renew).'</td>';
        echo '<td class="be-qms-row">'
          .($emp_cell ? '<a class="be-qms-btn be-qms-btn-secondary" href="'.$emp_view.'">View</a>' : '')
          .($emp_cell ? '<a class="be-qms-btn be-qms-btn-danger" href="'.$emp_del.'" onclick="return confirm(\'Remove this employee and their training records?\')">Remove</a>' : '')
          .(!$emp_cell ? '<a class="be-qms-btn be-qms-btn-danger" href="'.$skill_del.'" onclick="return confirm(\'Remove this skill record?\')">Remove</a>' : '')
          .'</td>';
        echo '</tr>';

        $last_emp = $eid;
      }
    }

    echo '</tbody></table>';
  }

  private static function render_r07_employee_form($id) {
    $is_edit = $id > 0;
    $name = '';
    if ($is_edit) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_EMPLOYEE) {
        echo '<div class="be-qms-muted">Employee not found.</div>';
        return;
      }
      $name = $p->post_title;
    }

    echo '<h3>'.($is_edit ? 'Edit employee' : 'Add new employee').'</h3>';

    $save_url = esc_url(admin_url('admin-post.php'));
    echo '<form method="post" action="'.$save_url.'">';
    echo '<input type="hidden" name="action" value="be_qms_save_employee" />';
    wp_nonce_field('be_qms_save_employee');
    if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($id).'" />';

    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-12"><label><strong>Employee name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="employee_name" value="'.esc_attr($name).'" required /></label></div>';
    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">Save</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';
    echo '</form>';
  }

  private static function render_r07_employee_view($employee_id) {
    $p = get_post($employee_id);
    if (!$p || $p->post_type !== self::CPT_EMPLOYEE) {
      echo '<div class="be-qms-muted">Employee not found.</div>';
      return;
    }

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">R07 - Personal Skills &amp; Training Matrix</h3><div class="be-qms-muted">Employee: <strong>'.esc_html($p->post_title).'</strong></div></div>';
    $return_url = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix'], self::portal_url()));
    echo '<div class="be-qms-row"><a class="be-qms-btn be-qms-btn-secondary" href="'.$return_url.'">Return to Records</a></div>';
    echo '</div>';

    echo '<h4 style="margin-top:16px">Add New Skill Record</h4>';
    self::render_r07_skill_form($employee_id, 0, false);

    echo '<h4 style="margin-top:16px">Skill Records</h4>';
    $skills = get_posts([
      'post_type' => self::CPT_TRAINING,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_key' => self::META_EMPLOYEE_LINK,
      'meta_value' => $employee_id,
    ]);

    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Course</th><th>Date of course</th><th>Renewal date</th><th>Actions</th></tr></thead><tbody>';
    if (!$skills) {
      echo '<tr><td colspan="4" class="be-qms-muted">No skill records yet.</td></tr>';
    } else {
      foreach ($skills as $s) {
        $sid = (int)$s->ID;
        $course = get_post_meta($sid, '_be_qms_training_course', true);
        $date_course = get_post_meta($sid, '_be_qms_training_date', true);
        $renew = get_post_meta($sid, '_be_qms_training_renewal', true);
        $edit = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'edit_skill','emp'=>$employee_id,'id'=>$sid], self::portal_url()));
        $del  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=training&id='.$sid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$sid)));
        echo '<tr>';
        echo '<td>'.esc_html($course ?: $s->post_title).'</td>';
        $display_course_date = $date_course ? self::format_date_for_display($date_course) : '—';
        $display_renew = $renew ? self::format_date_for_display($renew) : '—';
        echo '<td>'.esc_html($display_course_date).'</td>';
        echo '<td>'.esc_html($display_renew).'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del.'" onclick="return confirm(\'Delete this skill record?\')">Delete</a>'
          .'</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';

    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">← Return to R07</a></div>';
  }

  private static function render_r07_skill_form($employee_id, $skill_id, $show_header = true) {
    $emp = get_post($employee_id);
    if (!$emp || $emp->post_type !== self::CPT_EMPLOYEE) {
      echo '<div class="be-qms-muted">Employee not found.</div>';
      return;
    }

    $is_edit = $skill_id > 0;
    $course = '';
    $date_course = '';
    $renew = '';
    $desc = '';
    $att_ids = [];

    if ($is_edit) {
      $p = get_post($skill_id);
      if (!$p || $p->post_type !== self::CPT_TRAINING) {
        echo '<div class="be-qms-muted">Training record not found.</div>';
        return;
      }
      $course = get_post_meta($skill_id, '_be_qms_training_course', true);
      $date_course = get_post_meta($skill_id, '_be_qms_training_date', true);
      $renew = get_post_meta($skill_id, '_be_qms_training_renewal', true);
      $desc = get_post_meta($skill_id, '_be_qms_training_desc', true);
      $att_ids = get_post_meta($skill_id, '_be_qms_attachments', true);
      if (!is_array($att_ids)) $att_ids = [];
    }

    if ($show_header) {
      echo '<h3>'.($is_edit ? 'Edit skill record' : 'Add new skill record').'</h3>';
      echo '<div class="be-qms-muted">Employee: '.esc_html($emp->post_title).'</div>';
    }

    $save_url = esc_url(admin_url('admin-post.php'));
    echo '<form method="post" action="'.$save_url.'" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="be_qms_save_training" />';
    wp_nonce_field('be_qms_save_training');
    echo '<input type="hidden" name="employee_id" value="'.esc_attr($employee_id).'" />';
    if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($skill_id).'" />';

    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-6"><label><strong>Course name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="course_name" value="'.esc_attr($course).'" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date of course</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="date_course" value="'.esc_attr(self::format_date_for_display($date_course)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Renewal date</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="renewal_date" value="'.esc_attr(self::format_date_for_display($renew)).'" placeholder="DD/MM/YYYY" /></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Description / certificates</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="description">'.esc_textarea($desc).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Attach certificates</strong> <span class="be-qms-muted">(optional)</span><br/>';
    echo '<input type="file" name="attachments[]" multiple /></label></div>';
    echo '</div>';

    if ($is_edit && $att_ids) {
      echo '<h4 style="margin-top:14px">Existing attachments</h4><ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        if (!$url) continue;
        $name = get_the_title($aid) ?: basename($url);
        echo '<li><label><input type="checkbox" name="remove_attachments[]" value="'.esc_attr($aid).'" /> Remove</label> &nbsp; <a class="be-qms-link" target="_blank" href="'.esc_url($url).'">'.esc_html($name).'</a></li>';
      }
      echo '</ul>';
    }

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">Save</button>';
    $back = esc_url(add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$employee_id], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';
    echo '</form>';
  }

  public static function handle_save_employee() {
    self::require_staff();
    check_admin_referer('be_qms_save_employee');

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $name = sanitize_text_field($_POST['employee_name'] ?? '');
    if (!$name) wp_die('Missing employee name.');

    if ($id) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_EMPLOYEE) wp_die('Not found');
      wp_update_post(['ID'=>$id,'post_title'=>$name]);
      $eid = $id;
    } else {
      $eid = wp_insert_post([
        'post_type' => self::CPT_EMPLOYEE,
        'post_status' => 'publish',
        'post_title' => $name,
      ], true);
      if (is_wp_error($eid)) wp_die('Failed to save employee.');
    }

    $url = add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$eid], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_save_training() {
    self::require_staff();
    check_admin_referer('be_qms_save_training');

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
    $course = sanitize_text_field($_POST['course_name'] ?? '');
    $date_course = self::normalize_date_input($_POST['date_course'] ?? '');
    $renew = self::normalize_date_input($_POST['renewal_date'] ?? '');
    $desc = wp_kses_post($_POST['description'] ?? '');

    if (!$employee_id) wp_die('Missing employee.');
    if (!$course) wp_die('Missing course name.');

    $title = $course;

    if ($id) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_TRAINING) wp_die('Not found');
      wp_update_post(['ID'=>$id,'post_title'=>$title]);
      $tid = $id;
    } else {
      $tid = wp_insert_post([
        'post_type' => self::CPT_TRAINING,
        'post_status' => 'publish',
        'post_title' => $title,
      ], true);
      if (is_wp_error($tid)) wp_die('Failed to save training record.');
    }

    update_post_meta($tid, self::META_EMPLOYEE_LINK, $employee_id);
    update_post_meta($tid, '_be_qms_training_course', $course);
    update_post_meta($tid, '_be_qms_training_date', $date_course);
    update_post_meta($tid, '_be_qms_training_renewal', $renew);
    update_post_meta($tid, '_be_qms_training_desc', $desc);

    // Attachment handling (merge + removals)
    $existing = get_post_meta($tid, '_be_qms_attachments', true);
    if (!is_array($existing)) $existing = [];

    $remove = array_map('intval', (array) ($_POST['remove_attachments'] ?? []));
    if ($remove) {
      $existing = array_values(array_diff($existing, $remove));
    }

    $new = self::handle_uploads('attachments');
    if ($new) $existing = array_values(array_unique(array_merge($existing, $new)));

    update_post_meta($tid, '_be_qms_attachments', $existing);

    $url = add_query_arg(['view'=>'records','type'=>'r07_training_matrix','be_action'=>'employee','id'=>$employee_id], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_save_record() {
    self::require_staff();
    check_admin_referer('be_qms_save_record');

    $record_id = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
    $is_edit = $record_id > 0;

    $type = isset($_POST['record_type']) ? sanitize_key($_POST['record_type']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $date = isset($_POST['record_date']) ? self::normalize_date_input($_POST['record_date']) : '';
    $details = isset($_POST['details']) ? wp_kses_post($_POST['details']) : '';
    $actions = isset($_POST['actions']) ? wp_kses_post($_POST['actions']) : '';
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;

    if (!$type) {
      wp_die('Missing required fields.');
    }

    // R04 Tool Calibration uses structured fields (no generic Details required)
    if ($type === 'r04_tool_calibration') {
      $tool_item = sanitize_text_field($_POST['tool_item'] ?? '');
      $tool_description = sanitize_textarea_field($_POST['tool_description'] ?? '');
      $tool_requirements = sanitize_textarea_field($_POST['tool_requirements'] ?? '');
      $tool_date_purchased = self::normalize_date_input($_POST['tool_date_purchased'] ?? '');
      $tool_date_calibrated = self::normalize_date_input($_POST['tool_date_calibrated'] ?? '');
      $tool_next_due = self::normalize_date_input($_POST['tool_next_due'] ?? '');
      if (!$tool_item) wp_die('Missing required tool item.');
      if (!$title) {
        $title = 'R04 Tool – ' . $tool_item;
      }
      // Allow empty details/actions for this type
      $details = $tool_description;
      $actions = $tool_requirements;
      $date = $tool_next_due ?: ($tool_date_calibrated ?: ($tool_date_purchased ?: date('Y-m-d')));
    } elseif ($type === 'r06_customer_complaints') {
      $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
      $complaint_date = self::normalize_date_input($_POST['complaint_date'] ?? '');
      $nature = sanitize_textarea_field($_POST['nature'] ?? '');
      $title_suffix = $customer_name ? (' – ' . $customer_name) : '';
      $title_date = $complaint_date ? (' (' . self::format_date_for_display($complaint_date) . ')') : '';
      $title = $title ?: ('R06 Complaint' . $title_suffix . $title_date);
      $date = $complaint_date ?: date('Y-m-d');
      $details = $nature;
      $actions = sanitize_textarea_field($_POST['actions_taken'] ?? '');
    } elseif ($type === 'r02_capa') {
      $capa_date = self::normalize_date_input($_POST['capa_date'] ?? '');
      $ncr_no = sanitize_text_field($_POST['capa_ncr_no'] ?? '');
      $source = sanitize_text_field($_POST['capa_source'] ?? '');
      $action_type = sanitize_text_field($_POST['capa_action_type'] ?? '');
      $issued_to = sanitize_text_field($_POST['capa_issued_to'] ?? '');
      $no_days = sanitize_text_field($_POST['capa_no_days'] ?? '');
      $date_closed = self::normalize_date_input($_POST['capa_date_closed'] ?? '');
      $status = sanitize_text_field($_POST['capa_status'] ?? '');
      $closed_by = sanitize_text_field($_POST['capa_closed_by'] ?? '');
      $details_issue = sanitize_textarea_field($_POST['capa_details_issue'] ?? '');
      $summary_action = sanitize_textarea_field($_POST['capa_summary_action'] ?? '');
      $root_cause = sanitize_textarea_field($_POST['capa_root_cause'] ?? '');
      $prevent_recurrence = sanitize_textarea_field($_POST['capa_prevent_recurrence'] ?? '');

      $title_bits = array_filter([
        $ncr_no ? ('NCR ' . $ncr_no) : '',
        $capa_date ? self::format_date_for_display($capa_date) : '',
      ]);
      $title = $title ?: ('R02 CAPA' . ($title_bits ? (' – ' . implode(' • ', $title_bits)) : ''));

      $date = $capa_date ?: date('Y-m-d');
      $details = $details_issue;
      $actions = $summary_action;
    } elseif ($type === 'r05_internal_review') {
      $review_date = self::normalize_date_input($_POST['r05_review_date'] ?? '');
      $period_from = self::normalize_date_input($_POST['r05_period_from'] ?? '');
      $period_to = self::normalize_date_input($_POST['r05_period_to'] ?? '');
      $reviewer = sanitize_text_field($_POST['r05_reviewer'] ?? '');
      $attendees = sanitize_text_field($_POST['r05_attendees'] ?? '');
      $scope = sanitize_textarea_field($_POST['r05_scope'] ?? '');
      $findings = sanitize_textarea_field($_POST['r05_findings'] ?? '');
      $nonconformities = sanitize_textarea_field($_POST['r05_nonconformities'] ?? '');
      $actions_taken = sanitize_textarea_field($_POST['r05_actions'] ?? '');
      $decision = sanitize_textarea_field($_POST['r05_decision'] ?? '');
      $next_review_date = self::normalize_date_input($_POST['r05_next_review_date'] ?? '');
      $status = sanitize_text_field($_POST['r05_status'] ?? '');
      $allowed_statuses = ['Open', 'Closed'];
      if (!in_array($status, $allowed_statuses, true)) {
        $status = '';
      }

      if (!$review_date) {
        wp_die('Missing review date.');
      }

      $period_bits = array_filter([
        $period_from ? self::format_date_for_display($period_from) : '',
        $period_to ? self::format_date_for_display($period_to) : '',
      ]);
      $title_bits = array_filter([
        $period_bits ? implode(' – ', $period_bits) : '',
        $reviewer ?: '',
      ]);
      $title = $title ?: ('R05 Internal Review' . ($title_bits ? (' – ' . implode(' • ', $title_bits)) : ''));
      $date = $review_date ?: date('Y-m-d');
      $details = $findings ?: $scope;
      $actions = $actions_taken;
    } elseif ($type === 'r03_purchase_order') {
      $po_number = sanitize_text_field($_POST['r03_po_number'] ?? '');
      $customer_ref = sanitize_text_field($_POST['r03_customer_ref'] ?? '');
      $supplier_id = isset($_POST['r03_supplier_id']) ? (int) $_POST['r03_supplier_id'] : 0;
      $supplier_name = $supplier_id ? get_the_title($supplier_id) : '';
      $description = sanitize_textarea_field($_POST['r03_description'] ?? '');
      $raised_by = sanitize_text_field($_POST['r03_raised_by'] ?? '');
      $date_raised = self::normalize_date_input($_POST['r03_date_raised'] ?? '');
      $inverter_model = sanitize_text_field($_POST['r03_inverter_model'] ?? '');
      $solar_panels = sanitize_text_field($_POST['r03_solar_panels'] ?? '');
      $solar_panels_qty = sanitize_text_field($_POST['r03_solar_panels_qty'] ?? '');
      $battery_model = sanitize_text_field($_POST['r03_battery_model'] ?? '');
      $other_equipment = sanitize_textarea_field($_POST['r03_other_equipment'] ?? '');
      $template_toggle = !empty($_POST['r03_template_toggle']);
      $save_template = $template_toggle && !empty($_POST['r03_save_template']) ? '1' : '';
      $template_name = sanitize_text_field($_POST['r03_template_name'] ?? '');

      $title_bits = array_filter([
        $po_number ? ('PO ' . $po_number) : '',
        $supplier_name ?: '',
      ]);
      if ($template_toggle && $save_template && $template_name) {
        $title = 'R03 Template – ' . $template_name;
      } else {
        $title = $title ?: ('R03 Purchase Order' . ($title_bits ? (' – ' . implode(' • ', $title_bits)) : ''));
      }
      $date = $date_raised ?: date('Y-m-d');
      $details = $description;
      $actions = '';
    } elseif ($type === 'r08_approved_suppliers') {
      $supplier_name = sanitize_text_field($_POST['r08_supplier_name'] ?? '');
      $services = sanitize_text_field($_POST['r08_services'] ?? '');
      $contact_name = sanitize_text_field($_POST['r08_contact_name'] ?? '');
      $contact_email = sanitize_email($_POST['r08_contact_email'] ?? '');
      $contact_phone = sanitize_text_field($_POST['r08_contact_phone'] ?? '');
      $address = sanitize_textarea_field($_POST['r08_address'] ?? '');
      $approved_by = sanitize_text_field($_POST['r08_approved_by'] ?? '');
      $approval_date = self::normalize_date_input($_POST['r08_approval_date'] ?? '');
      $review_date = self::normalize_date_input($_POST['r08_review_date'] ?? '');
      $status = sanitize_text_field($_POST['r08_status'] ?? '');
      $notes = sanitize_textarea_field($_POST['r08_notes'] ?? '');

      if (!$supplier_name) {
        wp_die('Missing supplier name.');
      }

      $title = $title ?: ('R08 Supplier – ' . $supplier_name);
      $date = $approval_date ?: date('Y-m-d');
      $details = $services ?: $notes;
      $actions = $notes;
    } elseif ($type === 'r09_approved_subcontract') {
      $subcontractor_name = sanitize_text_field($_POST['r09_subcontractor_name'] ?? '');
      $services = sanitize_text_field($_POST['r09_services'] ?? '');
      $contact_name = sanitize_text_field($_POST['r09_contact_name'] ?? '');
      $contact_email = sanitize_email($_POST['r09_contact_email'] ?? '');
      $contact_phone = sanitize_text_field($_POST['r09_contact_phone'] ?? '');
      $address = sanitize_textarea_field($_POST['r09_address'] ?? '');
      $approval_date = self::normalize_date_input($_POST['r09_approval_date'] ?? '');
      $review_date = self::normalize_date_input($_POST['r09_review_date'] ?? '');
      $status = sanitize_text_field($_POST['r09_status'] ?? '');
      $employee_id = isset($_POST['r09_employee_id']) ? (int) $_POST['r09_employee_id'] : 0;
      $notes = sanitize_textarea_field($_POST['r09_notes'] ?? '');

      if (!$subcontractor_name) {
        wp_die('Missing subcontractor name.');
      }

      $title = $title ?: ('R09 Subcontractor – ' . $subcontractor_name);
      $date = $approval_date ?: date('Y-m-d');
      $details = $services ?: $notes;
      $actions = $notes;
    } elseif ($type === 'r11_company_documents') {
      $doc_title = sanitize_text_field($_POST['r11_doc_title'] ?? '');
      $category = sanitize_text_field($_POST['r11_category'] ?? '');
      $reference = sanitize_text_field($_POST['r11_reference'] ?? '');
      $version = sanitize_text_field($_POST['r11_version'] ?? '');
      $owner = sanitize_text_field($_POST['r11_owner'] ?? '');
      $location = sanitize_text_field($_POST['r11_location'] ?? '');
      $link = esc_url_raw($_POST['r11_link'] ?? '');
      $issue_date = self::normalize_date_input($_POST['r11_issue_date'] ?? '');
      $review_date = self::normalize_date_input($_POST['r11_review_date'] ?? '');
      $status = sanitize_text_field($_POST['r11_status'] ?? '');
      $notes = sanitize_textarea_field($_POST['r11_notes'] ?? '');

      if (!$doc_title) {
        wp_die('Missing document title.');
      }

      $title = $title ?: ('R11 Document – ' . $doc_title);
      $date = $issue_date ?: date('Y-m-d');
      $details = $notes;
      $actions = '';
    } else {
      if (!$title || !$date || !$details) {
        wp_die('Missing required fields.');
      }
    }

    if ($is_edit) {
      if (!current_user_can('edit_post', $record_id)) wp_die('No permission.');
      $updated = wp_update_post([
        'ID' => $record_id,
        'post_title' => $title,
      ], true);
      if (is_wp_error($updated)) wp_die('Failed to update record.');
      $pid = $record_id;
    } else {
      $pid = wp_insert_post([
        'post_type' => self::CPT_RECORD,
        'post_status' => 'publish',
        'post_title' => $title,
      ], true);
      if (is_wp_error($pid)) wp_die('Failed to save record.');
    }

    wp_set_object_terms($pid, [$type], self::TAX_RECORD_TYPE, false);
    update_post_meta($pid, '_be_qms_record_date', $date);
    update_post_meta($pid, '_be_qms_details', $details);
    update_post_meta($pid, '_be_qms_actions', $actions);

    if ($type === 'r04_tool_calibration') {
      update_post_meta($pid, '_be_qms_tool_item', sanitize_text_field($_POST['tool_item'] ?? ''));
      update_post_meta($pid, '_be_qms_tool_serial', sanitize_text_field($_POST['tool_serial'] ?? ''));
      update_post_meta($pid, '_be_qms_tool_description', sanitize_textarea_field($_POST['tool_description'] ?? ''));
      update_post_meta($pid, '_be_qms_tool_requirements', sanitize_textarea_field($_POST['tool_requirements'] ?? ''));
      update_post_meta($pid, '_be_qms_tool_date_purchased', $tool_date_purchased);
      update_post_meta($pid, '_be_qms_tool_date_calibrated', $tool_date_calibrated);
      update_post_meta($pid, '_be_qms_tool_next_due', $tool_next_due);
    }

    if ($type === 'r06_customer_complaints') {
      $date_closed = self::normalize_date_input($_POST['date_closed'] ?? '');
      update_post_meta($pid, '_be_qms_r06_customer_name', sanitize_text_field($_POST['customer_name'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_address', sanitize_textarea_field($_POST['address'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_complaint_date', $complaint_date);
      update_post_meta($pid, '_be_qms_r06_contact_name', sanitize_text_field($_POST['contact_name'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_contact_email', sanitize_email($_POST['contact_email'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_contact_phone', sanitize_text_field($_POST['contact_phone'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_contact_mobile', sanitize_text_field($_POST['contact_mobile'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_nature', sanitize_text_field($_POST['nature'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_outcome', sanitize_text_field($_POST['outcome'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_immediate_action', sanitize_textarea_field($_POST['immediate_action'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_contacted_within_day', sanitize_text_field($_POST['contacted_within_day'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_contacted_reason', sanitize_textarea_field($_POST['contacted_reason'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_actions_taken', sanitize_textarea_field($_POST['actions_taken'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_further_action', sanitize_textarea_field($_POST['further_action'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_customer_satisfied', sanitize_text_field($_POST['customer_satisfied'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_reported_by', sanitize_text_field($_POST['reported_by'] ?? ''));
      update_post_meta($pid, '_be_qms_r06_date_closed', $date_closed);
      update_post_meta($pid, '_be_qms_r06_reported_title', sanitize_text_field($_POST['reported_title'] ?? ''));
    }

    if ($type === 'r02_capa') {
      update_post_meta($pid, '_be_qms_capa_date', $capa_date);
      update_post_meta($pid, '_be_qms_capa_ncr_no', $ncr_no);
      update_post_meta($pid, '_be_qms_capa_source', $source);
      update_post_meta($pid, '_be_qms_capa_action_type', $action_type);
      update_post_meta($pid, '_be_qms_capa_issued_to', $issued_to);
      update_post_meta($pid, '_be_qms_capa_no_days', $no_days);
      update_post_meta($pid, '_be_qms_capa_date_closed', $date_closed);
      update_post_meta($pid, '_be_qms_capa_status', $status);
      update_post_meta($pid, '_be_qms_capa_closed_by', $closed_by);
      update_post_meta($pid, '_be_qms_capa_details_issue', $details_issue);
      update_post_meta($pid, '_be_qms_capa_summary_action', $summary_action);
      update_post_meta($pid, '_be_qms_capa_root_cause', $root_cause);
      update_post_meta($pid, '_be_qms_capa_prevent_recurrence', $prevent_recurrence);
    }

    if ($type === 'r05_internal_review') {
      update_post_meta($pid, '_be_qms_r05_review_date', $review_date);
      update_post_meta($pid, '_be_qms_r05_period_from', $period_from);
      update_post_meta($pid, '_be_qms_r05_period_to', $period_to);
      update_post_meta($pid, '_be_qms_r05_reviewer', $reviewer);
      update_post_meta($pid, '_be_qms_r05_attendees', $attendees);
      update_post_meta($pid, '_be_qms_r05_scope', $scope);
      update_post_meta($pid, '_be_qms_r05_findings', $findings);
      update_post_meta($pid, '_be_qms_r05_nonconformities', $nonconformities);
      update_post_meta($pid, '_be_qms_r05_actions', $actions_taken);
      update_post_meta($pid, '_be_qms_r05_decision', $decision);
      update_post_meta($pid, '_be_qms_r05_next_review_date', $next_review_date);
      update_post_meta($pid, '_be_qms_r05_status', $status);
    }

    if ($type === 'r03_purchase_order') {
      update_post_meta($pid, '_be_qms_r03_po_number', $po_number);
      update_post_meta($pid, '_be_qms_r03_customer_ref', $customer_ref);
      update_post_meta($pid, '_be_qms_r03_supplier_id', $supplier_id);
      update_post_meta($pid, '_be_qms_r03_supplier_name', $supplier_name);
      update_post_meta($pid, '_be_qms_r03_description', $description);
      update_post_meta($pid, '_be_qms_r03_raised_by', $raised_by);
      update_post_meta($pid, '_be_qms_r03_date_raised', $date_raised);
      update_post_meta($pid, '_be_qms_r03_inverter_model', $inverter_model);
      update_post_meta($pid, '_be_qms_r03_solar_panels', $solar_panels);
      update_post_meta($pid, '_be_qms_r03_solar_panels_qty', $solar_panels_qty);
      update_post_meta($pid, '_be_qms_r03_battery_model', $battery_model);
      update_post_meta($pid, '_be_qms_r03_other_equipment', $other_equipment);
      if ($save_template) {
        update_post_meta($pid, '_be_qms_r03_is_template', '1');
        update_post_meta($pid, '_be_qms_r03_template_name', $template_name);
      } else {
        delete_post_meta($pid, '_be_qms_r03_is_template');
        delete_post_meta($pid, '_be_qms_r03_template_name');
      }
    }

    if ($type === 'r08_approved_suppliers') {
      update_post_meta($pid, '_be_qms_r08_supplier_name', $supplier_name);
      update_post_meta($pid, '_be_qms_r08_services', $services);
      update_post_meta($pid, '_be_qms_r08_contact_name', $contact_name);
      update_post_meta($pid, '_be_qms_r08_contact_email', $contact_email);
      update_post_meta($pid, '_be_qms_r08_contact_phone', $contact_phone);
      update_post_meta($pid, '_be_qms_r08_address', $address);
      update_post_meta($pid, '_be_qms_r08_approved_by', $approved_by);
      update_post_meta($pid, '_be_qms_r08_approval_date', $approval_date);
      update_post_meta($pid, '_be_qms_r08_review_date', $review_date);
      update_post_meta($pid, '_be_qms_r08_status', $status);
      update_post_meta($pid, '_be_qms_r08_notes', $notes);
    }

    if ($type === 'r09_approved_subcontract') {
      update_post_meta($pid, '_be_qms_r09_subcontractor_name', $subcontractor_name);
      update_post_meta($pid, '_be_qms_r09_services', $services);
      update_post_meta($pid, '_be_qms_r09_contact_name', $contact_name);
      update_post_meta($pid, '_be_qms_r09_contact_email', $contact_email);
      update_post_meta($pid, '_be_qms_r09_contact_phone', $contact_phone);
      update_post_meta($pid, '_be_qms_r09_address', $address);
      update_post_meta($pid, '_be_qms_r09_approval_date', $approval_date);
      update_post_meta($pid, '_be_qms_r09_review_date', $review_date);
      update_post_meta($pid, '_be_qms_r09_status', $status);
      update_post_meta($pid, '_be_qms_r09_employee_id', $employee_id);
      update_post_meta($pid, '_be_qms_r09_notes', $notes);
    }

    if ($type === 'r11_company_documents') {
      update_post_meta($pid, '_be_qms_r11_doc_title', $doc_title);
      update_post_meta($pid, '_be_qms_r11_category', $category);
      update_post_meta($pid, '_be_qms_r11_reference', $reference);
      update_post_meta($pid, '_be_qms_r11_version', $version);
      update_post_meta($pid, '_be_qms_r11_owner', $owner);
      update_post_meta($pid, '_be_qms_r11_location', $location);
      update_post_meta($pid, '_be_qms_r11_link', $link);
      update_post_meta($pid, '_be_qms_r11_issue_date', $issue_date);
      update_post_meta($pid, '_be_qms_r11_review_date', $review_date);
      update_post_meta($pid, '_be_qms_r11_status', $status);
      update_post_meta($pid, '_be_qms_r11_notes', $notes);
    }

    if ($project_id > 0) {
      update_post_meta($pid, self::META_PROJECT_LINK, $project_id);
    } else {
      delete_post_meta($pid, self::META_PROJECT_LINK);
    }

    $subcontractor_id = isset($_POST['subcontractor_id']) ? (int) $_POST['subcontractor_id'] : 0;
    if ($subcontractor_id > 0) {
      update_post_meta($pid, '_be_qms_subcontractor_id', $subcontractor_id);
    } else {
      delete_post_meta($pid, '_be_qms_subcontractor_id');
    }

    $existing = get_post_meta($pid, '_be_qms_attachments', true);
    if (!is_array($existing)) $existing = [];

    $remove = isset($_POST['remove_attachments']) ? (array) $_POST['remove_attachments'] : [];
    $remove = array_map('intval', $remove);
    if ($remove) {
      $existing = array_values(array_diff(array_map('intval', $existing), $remove));
    }

    $new_att_ids = self::handle_uploads('attachments');
    if ($new_att_ids) {
      $existing = array_values(array_unique(array_merge($existing, $new_att_ids)));
    }

    update_post_meta($pid, '_be_qms_attachments', $existing);

    $save_action = sanitize_key($_POST['save_action'] ?? '');
    if ($type === 'r03_purchase_order') {
      if ($save_action === 'uploads') {
        $url = add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$pid], self::portal_url());
      } elseif ($save_action === 'close') {
        $url = add_query_arg(['view'=>'records','type'=>'r03_purchase_order'], self::portal_url());
      } else {
        $url = add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'edit','id'=>$pid], self::portal_url());
      }
    } else {
      $url = add_query_arg(['view'=>'records','be_action'=>'view','id'=>$pid,'type'=>$type], self::portal_url());
    }
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_save_r03_upload() {
    self::require_staff();
    check_admin_referer('be_qms_save_r03_upload');

    $record_id = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
    if (!$record_id) {
      wp_die('Missing record.');
    }

    $upload_type = sanitize_key($_POST['upload_type'] ?? '');
    if (!in_array($upload_type, ['document', 'image'], true)) {
      wp_die('Invalid upload type.');
    }

    $new_ids = self::handle_uploads('r03_upload');
    if (!$new_ids) {
      wp_die('No file uploaded.');
    }

    $meta_key = ($upload_type === 'document') ? '_be_qms_r03_documents' : '_be_qms_r03_photos';
    $existing = get_post_meta($record_id, $meta_key, true);
    if (!is_array($existing)) $existing = [];
    $existing = array_values(array_unique(array_merge($existing, $new_ids)));
    update_post_meta($record_id, $meta_key, $existing);

    $url = add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$record_id], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_remove_r03_upload() {
    self::require_staff();

    $record_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $attachment_id = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;
    $upload_type = sanitize_key($_GET['upload_type'] ?? '');

    if (!$record_id || !$attachment_id) {
      wp_die('Missing data.');
    }

    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'be_qms_remove_r03_upload_'.$record_id)) {
      wp_die('Invalid nonce.');
    }

    $meta_key = ($upload_type === 'image') ? '_be_qms_r03_photos' : '_be_qms_r03_documents';
    $existing = get_post_meta($record_id, $meta_key, true);
    if (!is_array($existing)) $existing = [];
    $existing = array_values(array_diff(array_map('intval', $existing), [$attachment_id]));
    update_post_meta($record_id, $meta_key, $existing);

    $url = add_query_arg(['view'=>'records','type'=>'r03_purchase_order','be_action'=>'uploads','id'=>$record_id], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  /* -------------------------------
   * Projects
   * ------------------------------*/

  private static function render_projects() {
    $action = self::get_portal_action();

    if ($action === 'new') {
      self::render_project_form(0);
      return;
    }
    if ($action === 'edit' && !empty($_GET['id'])) {
      self::render_project_form((int)$_GET['id']);
      return;
    }
    if ($action === 'view' && !empty($_GET['id'])) {
      self::render_project_view((int)$_GET['id']);
      return;
    }

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><strong>Projects</strong> <span class="be-qms-muted">(your installation/job files)</span></div>';
    $new_url = esc_url(add_query_arg(['view'=>'projects','be_action'=>'new'], self::portal_url()));
    echo '<a class="be-qms-btn" href="'.$new_url.'">Add New</a>';
    echo '</div>';

    $q = new WP_Query([
      'post_type' => self::CPT_PROJECT,
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC'
    ]);

    echo '<table class="be-qms-table">';
    echo '<thead><tr><th>Date</th><th>Project</th><th>Customer</th><th>Actions</th></tr></thead><tbody>';

    if (!$q->have_posts()) {
      echo '<tr><td colspan="4" class="be-qms-muted">No projects yet. Create one for your assessment pack.</td></tr>';
    } else {
      while ($q->have_posts()) {
        $q->the_post();
        $pid = get_the_ID();
        $customer = get_post_meta($pid, '_be_qms_customer', true);

        $view_url = esc_url(add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$pid], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'projects','be_action'=>'edit','id'=>$pid], self::portal_url()));
        $print_url= esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$pid], self::portal_url()));
        $del_url  = esc_url(admin_url('admin-post.php?action=be_qms_delete&kind=project&id='.$pid.'&_wpnonce='.wp_create_nonce('be_qms_delete_'.$pid)));

        echo '<tr>';
        echo '<td>'.esc_html(self::format_date_for_display(get_the_date('Y-m-d'))).'</td>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html(get_the_title()).'</a></td>';
        echo '<td>'.esc_html($customer ?: '-').'</td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print/PDF</a>'
          .'<a class="be-qms-btn be-qms-btn-danger" href="'.$del_url.'" onclick="return confirm(\'Delete this project?\')">Delete</a>'
          .'</td>';
        echo '</tr>';
      }
      wp_reset_postdata();
    }

    echo '</tbody></table>';
  }

  private static function render_project_form($id = 0) {
    $is_edit = $id > 0;

    $title = '';
    $customer = '';
    $address = '';
    $pv_kwp = '';
    $bess_kwh = '';
    $contract_signed = '';
    $notes = '';
    $has_subcontractor = 'no';
    $subcontractor_id = 0;
    $existing_att_ids = [];
    $handover_checklist = [];

    if ($is_edit) {
      $p = get_post($id);
      if (!$p || $p->post_type !== self::CPT_PROJECT) {
        echo '<div class="be-qms-muted">Project not found.</div>';
        return;
      }
      if (!current_user_can('edit_post', $id)) {
        wp_die('You do not have permission to edit this project.');
      }

      $title = $p->post_title;
      $customer = (string) get_post_meta($id, '_be_qms_customer', true);
      $address  = (string) get_post_meta($id, '_be_qms_address', true);
      $pv_kwp   = (string) get_post_meta($id, '_be_qms_pv_kwp', true);
      $bess_kwh = (string) get_post_meta($id, '_be_qms_bess_kwh', true);
      $contract_signed = (string) get_post_meta($id, '_be_qms_contract_signed', true);
      $notes    = (string) get_post_meta($id, '_be_qms_notes', true);
      $has_subcontractor = (string) get_post_meta($id, '_be_qms_project_has_subcontractor', true);
      $has_subcontractor = $has_subcontractor === 'yes' ? 'yes' : 'no';
      $subcontractor_id = (int) get_post_meta($id, '_be_qms_project_subcontractor_id', true);
      $existing_att_ids = get_post_meta($id, '_be_qms_evidence', true);
      if (!is_array($existing_att_ids)) $existing_att_ids = [];
      $handover_checklist = get_post_meta($id, '_be_qms_handover_checklist', true);
      if (!is_array($handover_checklist)) $handover_checklist = [];
    }

    echo '<h3>'.($is_edit ? 'Edit project' : 'New project').'</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="be_qms_save_project" />';
    wp_nonce_field('be_qms_save_project');
    if ($is_edit) {
      echo '<input type="hidden" name="project_id" value="'.esc_attr($id).'" />';
    }

    echo '<div class="be-qms-grid">';

    echo '<div class="be-qms-col-12"><label><strong>Project name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="title" value="'.esc_attr($title).'" placeholder="e.g. PV + Battery – 12 Example Road – Jan 2026" required /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Customer name</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="customer" value="'.esc_attr($customer).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Site address</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="address" value="'.esc_attr($address).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>PV size (kWp)</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="pv_kwp" value="'.esc_attr($pv_kwp).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Battery size (kWh)</strong><br/>';
    echo '<input class="be-qms-input" type="text" name="bess_kwh" value="'.esc_attr($bess_kwh).'" /></label></div>';

    echo '<div class="be-qms-col-6"><label><strong>Date contract signed</strong><br/>';
    echo '<input class="be-qms-input be-qms-date" type="text" name="contract_signed" value="'.esc_attr(self::format_date_for_display($contract_signed)).'" placeholder="DD/MM/YYYY" /></label></div>';

    $subcontractors = self::query_r09_subcontractors();
    echo '<div class="be-qms-col-6"><label><strong>Has a subcontractor done this job?</strong><br/>';
    echo '<select class="be-qms-select" name="project_has_subcontractor">';
    $subcontractor_options = ['no' => 'No', 'yes' => 'Yes'];
    foreach ($subcontractor_options as $value => $label) {
      $selected = ($has_subcontractor === $value) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($value).'">'.esc_html($label).'</option>';
    }
    echo '</select></label></div>';

    $subcontractor_style = $has_subcontractor === 'yes' ? '' : 'display:none;';
    $subcontractor_disabled = $has_subcontractor === 'yes' ? '' : 'disabled';
    echo '<div class="be-qms-col-6" data-subcontractor-field style="'.$subcontractor_style.'"><label><strong>Subcontractor</strong><br/>';
    echo '<select class="be-qms-select" name="project_subcontractor_id" '.$subcontractor_disabled.'>';
    echo '<option value="">— Select —</option>';
    foreach ($subcontractors as $subcontractor) {
      $sid = (int) $subcontractor->ID;
      $label = get_post_meta($sid, '_be_qms_r09_subcontractor_name', true) ?: get_the_title($sid);
      $selected = ($sid === $subcontractor_id) ? 'selected' : '';
      echo '<option '.$selected.' value="'.esc_attr($sid).'">'.esc_html($label ?: ('Subcontractor #'.$sid)).'</option>';
    }
    echo '</select></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Notes</strong><br/>';
    echo '<textarea class="be-qms-textarea" name="notes">'.esc_textarea($notes).'</textarea></label></div>';

    echo '<div class="be-qms-col-12"><label><strong>Customer handover checklist</strong></label>';
    echo '<div class="be-qms-grid" style="margin-top:6px">';
    foreach (self::project_handover_items() as $key => $label) {
      $checked = !empty($handover_checklist[$key]) ? 'checked' : '';
      echo '<div class="be-qms-col-6"><label><input type="checkbox" name="handover_checklist['.esc_attr($key).']" value="1" '.$checked.'> '.esc_html($label).'</label></div>';
    }
    echo '</div></div>';

    echo '<div class="be-qms-col-12">';
    echo '<label><strong>Evidence uploads</strong> <span class="be-qms-muted">(multiple files)</span></label>';

    if ($is_edit && !empty($existing_att_ids)) {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">Tick any files you want to remove, and/or upload more below.</div>';
      echo '<ul style="margin:0;padding-left:18px">';
      foreach ($existing_att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        if (!$url) continue;
        $name = get_the_title($aid) ?: basename((string)$url);
        echo '<li style="margin:6px 0">'
          .'<label style="display:flex;gap:10px;align-items:center">'
          .'<input type="checkbox" name="remove_evidence[]" value="'.esc_attr((int)$aid).'">'
          .'<a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name).'</a>'
          .'</label>'
          .'</li>';
      }
      echo '</ul>';
    } else {
      echo '<div class="be-qms-muted" style="margin:6px 0 8px 0">None yet.</div>';
    }

    echo '<div style="margin-top:8px"><input type="file" name="evidence[]" multiple /></div>';
    echo '</div>';

    echo '</div>';

    echo '<div class="be-qms-row" style="margin-top:12px">';
    echo '<button class="be-qms-btn" type="submit">'.($is_edit ? 'Save changes' : 'Save project').'</button>';
    $back = esc_url(add_query_arg(['view'=>'projects'], self::portal_url()));
    echo '<a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Cancel</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_project_view($id) {
    $p = get_post($id);
    if (!$p || $p->post_type !== self::CPT_PROJECT) {
      echo '<div class="be-qms-muted">Project not found.</div>';
      return;
    }

    $customer = get_post_meta($id, '_be_qms_customer', true);
    $address  = get_post_meta($id, '_be_qms_address', true);
    $pv_kwp   = get_post_meta($id, '_be_qms_pv_kwp', true);
    $bess_kwh = get_post_meta($id, '_be_qms_bess_kwh', true);
    $contract_signed = get_post_meta($id, '_be_qms_contract_signed', true);
    $notes    = get_post_meta($id, '_be_qms_notes', true);
    $has_subcontractor = get_post_meta($id, '_be_qms_project_has_subcontractor', true) === 'yes' ? 'yes' : 'no';
    $subcontractor_id = (int) get_post_meta($id, '_be_qms_project_subcontractor_id', true);
    $att_ids  = get_post_meta($id, '_be_qms_evidence', true);
    if (!is_array($att_ids)) $att_ids = [];
    $handover_checklist = get_post_meta($id, '_be_qms_handover_checklist', true);
    if (!is_array($handover_checklist)) $handover_checklist = [];

    $print_url= esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$id], self::portal_url()));
    $edit_url = esc_url(add_query_arg(['view'=>'projects','be_action'=>'edit','id'=>$id], self::portal_url()));
    $record_defs = self::record_type_definitions();

    echo '<div class="be-qms-row" style="justify-content:space-between">';
    echo '<div><h3 style="margin:0">'.esc_html($p->post_title).'</h3><div class="be-qms-muted">Project file (assessment/job)</div></div>';
    echo '<div class="be-qms-row">'
      .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit project</a>'
      .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print / Save PDF</a>'
      .'</div>';
    echo '</div>';

    echo '<form method="get" action="'.esc_url(self::portal_url()).'" class="be-qms-row" style="margin-top:12px">';
    echo '<input type="hidden" name="view" value="records" />';
    echo '<input type="hidden" name="be_action" value="new" />';
    echo '<input type="hidden" name="project_id" value="'.esc_attr($id).'" />';
    echo '<label><strong>Add record for this project</strong><br/>';
    echo '<select class="be-qms-select" name="type" style="min-width:260px">';
    foreach ($record_defs as $slug => $label) {
      echo '<option value="'.esc_attr($slug).'">'.esc_html($label).'</option>';
    }
    echo '</select></label>';
    echo '<button class="be-qms-btn" type="submit">Add record</button>';
    echo '</form>';

    echo '<div class="be-qms-card-inner">';
    echo '<div class="be-qms-grid">';
    echo '<div class="be-qms-col-6"><strong>Customer</strong><br/>'.esc_html($customer ?: '-').'</div>';
    echo '<div class="be-qms-col-6"><strong>Address</strong><br/>'.esc_html($address ?: '-').'</div>';
    echo '<div class="be-qms-col-6"><strong>PV (kWp)</strong><br/>'.esc_html($pv_kwp ?: '-').'</div>';
    echo '<div class="be-qms-col-6"><strong>Battery (kWh)</strong><br/>'.esc_html($bess_kwh ?: '-').'</div>';
    $display_contract_signed = $contract_signed ? self::format_date_for_display($contract_signed) : '—';
    echo '<div class="be-qms-col-6"><strong>Date contract signed</strong><br/>'.esc_html($display_contract_signed).'</div>';
    $subcontractor_label = '—';
    if ($has_subcontractor === 'yes' && $subcontractor_id > 0) {
      $subcontractor_label = get_post_meta($subcontractor_id, '_be_qms_r09_subcontractor_name', true) ?: get_the_title($subcontractor_id) ?: ('Subcontractor #'.$subcontractor_id);
    }
    echo '<div class="be-qms-col-6"><strong>Subcontractor used</strong><br/>'.esc_html($has_subcontractor === 'yes' ? 'Yes' : 'No').'</div>';
    if ($has_subcontractor === 'yes') {
      echo '<div class="be-qms-col-6"><strong>Subcontractor</strong><br/>'.esc_html($subcontractor_label).'</div>';
    }
    echo '</div>';

    echo '<h4 style="margin-top:14px">Customer handover checklist</h4>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="be_qms_save_project_checklist" />';
    echo '<input type="hidden" name="project_id" value="'.esc_attr($id).'" />';
    wp_nonce_field('be_qms_save_project_checklist');
    echo '<div class="be-qms-grid" style="margin-top:6px">';
    foreach (self::project_handover_items() as $key => $label) {
      $checked = !empty($handover_checklist[$key]) ? 'checked' : '';
      echo '<div class="be-qms-col-6"><label><input type="checkbox" name="handover_checklist['.esc_attr($key).']" value="1" '.$checked.'> '.esc_html($label).'</label></div>';
    }
    echo '</div>';
    echo '<div class="be-qms-row" style="margin-top:10px">';
    echo '<button class="be-qms-btn be-qms-btn-secondary" type="submit">Save checklist</button>';
    echo '</div>';
    echo '</form>';

    if (!empty($notes)) {
      echo '<h4 style="margin-top:14px">Notes</h4>';
      echo '<div>'.wpautop(esc_html($notes)).'</div>';
    }

    echo '<h4 style="margin-top:14px">Evidence uploads</h4>';
    if (!$att_ids) {
      echo '<div class="be-qms-muted">None uploaded yet.</div>';
    } else {
      echo '<ul>';
      foreach ($att_ids as $aid) {
        $url = wp_get_attachment_url($aid);
        $name = get_the_title($aid);
        if ($url) {
          echo '<li><a class="be-qms-link" href="'.esc_url($url).'" target="_blank">'.esc_html($name ?: basename($url)).'</a></li>';
        }
      }
      echo '</ul>';
    }

    // Linked records
    echo '<h4 style="margin-top:14px">Linked records</h4>';
    $rq = new WP_Query([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'meta_key' => self::META_PROJECT_LINK,
      'meta_value' => $id,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    if (!$rq->have_posts()) {
      echo '<div class="be-qms-muted">No linked records yet. Use “Add record for this project”.</div>';
    } else {
      echo '<table class="be-qms-table" style="margin-top:10px">';
      echo '<thead><tr><th>Date</th><th>Type</th><th>Title</th><th>Actions</th></tr></thead><tbody>';
      while ($rq->have_posts()) {
        $rq->the_post();
        $rid = get_the_ID();
        $record_date = get_post_meta($rid, '_be_qms_record_date', true) ?: get_the_date('Y-m-d');
        $terms = get_the_terms($rid, self::TAX_RECORD_TYPE);
        $slug = ($terms && !is_wp_error($terms)) ? $terms[0]->slug : '';
        $defs = self::record_type_definitions();
        $type_name = ($slug && isset($defs[$slug])) ? $defs[$slug] : (($terms && !is_wp_error($terms)) ? $terms[0]->name : '-');

        $view_url = esc_url(add_query_arg(['view'=>'records','be_action'=>'view','id'=>$rid,'type'=>($slug ?: array_key_first($defs))], self::portal_url()));
        $edit_url = esc_url(add_query_arg(['view'=>'records','be_action'=>'edit','id'=>$rid,'type'=>($slug ?: array_key_first($defs))], self::portal_url()));
        $doc_url  = esc_url(add_query_arg(['be_qms_export'=>'doc','post'=>$rid], self::portal_url()));
        $print_url= esc_url(add_query_arg(['be_qms_export'=>'print','post'=>$rid], self::portal_url()));

        echo '<tr>';
        echo '<td>'.esc_html(self::format_date_for_display($record_date)).'</td>';
        echo '<td>'.esc_html($type_name).'</td>';
        echo '<td><a class="be-qms-link" href="'.$view_url.'">'.esc_html(get_the_title()).'</a></td>';
        echo '<td class="be-qms-row">'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$view_url.'">View</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$edit_url.'">Edit</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" href="'.$doc_url.'">DOC</a>'
          .'<a class="be-qms-btn be-qms-btn-secondary" target="_blank" href="'.$print_url.'">Print/PDF</a>'
          .'</td>';
        echo '</tr>';
      }
      wp_reset_postdata();
      echo '</tbody></table>';
    }

    echo '</div>';

    $back = esc_url(add_query_arg(['view'=>'projects'], self::portal_url()));
    echo '<div class="be-qms-row" style="margin-top:12px"><a class="be-qms-btn be-qms-btn-secondary" href="'.$back.'">Return to Projects</a></div>';
  }

  public static function handle_save_project() {
    self::require_staff();
    check_admin_referer('be_qms_save_project');

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $is_edit = $project_id > 0;

    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    if (!$title) wp_die('Missing project name.');

    if ($is_edit) {
      if (!current_user_can('edit_post', $project_id)) wp_die('No permission.');
      $updated = wp_update_post([
        'ID' => $project_id,
        'post_title' => $title,
      ], true);
      if (is_wp_error($updated)) wp_die('Failed to update project.');
      $pid = $project_id;
    } else {
      $pid = wp_insert_post([
        'post_type' => self::CPT_PROJECT,
        'post_status' => 'publish',
        'post_title' => $title,
      ], true);
      if (is_wp_error($pid)) wp_die('Failed to save project.');
    }

    update_post_meta($pid, '_be_qms_customer', sanitize_text_field($_POST['customer'] ?? ''));
    update_post_meta($pid, '_be_qms_address', sanitize_text_field($_POST['address'] ?? ''));
    update_post_meta($pid, '_be_qms_pv_kwp', sanitize_text_field($_POST['pv_kwp'] ?? ''));
    update_post_meta($pid, '_be_qms_bess_kwh', sanitize_text_field($_POST['bess_kwh'] ?? ''));
    update_post_meta($pid, '_be_qms_contract_signed', self::normalize_date_input($_POST['contract_signed'] ?? ''));
    update_post_meta($pid, '_be_qms_notes', wp_kses_post($_POST['notes'] ?? ''));

    $has_subcontractor = sanitize_text_field($_POST['project_has_subcontractor'] ?? 'no');
    $has_subcontractor = $has_subcontractor === 'yes' ? 'yes' : 'no';
    $subcontractor_id = isset($_POST['project_subcontractor_id']) ? (int) $_POST['project_subcontractor_id'] : 0;
    if ($has_subcontractor === 'yes' && $subcontractor_id > 0) {
      $sub_post = get_post($subcontractor_id);
      if (!$sub_post || $sub_post->post_type !== self::CPT_RECORD) {
        $subcontractor_id = 0;
      } else {
        $terms = get_the_terms($subcontractor_id, self::TAX_RECORD_TYPE);
        $is_r09 = $terms && !is_wp_error($terms) && $terms[0]->slug === 'r09_approved_subcontract';
        if (!$is_r09) {
          $subcontractor_id = 0;
        }
      }
    } else {
      $subcontractor_id = 0;
    }
    update_post_meta($pid, '_be_qms_project_has_subcontractor', $has_subcontractor);
    update_post_meta($pid, '_be_qms_project_subcontractor_id', $subcontractor_id);

    $handover = isset($_POST['handover_checklist']) ? (array) $_POST['handover_checklist'] : [];
    $normalized_handover = [];
    foreach (self::project_handover_items() as $key => $label) {
      $normalized_handover[$key] = !empty($handover[$key]) ? 1 : 0;
    }
    update_post_meta($pid, '_be_qms_handover_checklist', $normalized_handover);

    $existing = get_post_meta($pid, '_be_qms_evidence', true);
    if (!is_array($existing)) $existing = [];

    $remove = isset($_POST['remove_evidence']) ? (array) $_POST['remove_evidence'] : [];
    $remove = array_map('intval', $remove);
    if ($remove) {
      $existing = array_values(array_diff(array_map('intval', $existing), $remove));
    }

    $new_att_ids = self::handle_uploads('evidence');
    if ($new_att_ids) {
      $existing = array_values(array_unique(array_merge($existing, $new_att_ids)));
    }

    update_post_meta($pid, '_be_qms_evidence', $existing);

    $url = add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$pid], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_save_project_checklist() {
    self::require_staff();
    check_admin_referer('be_qms_save_project_checklist');

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id) {
      wp_die('Missing project.');
    }
    if (!current_user_can('edit_post', $project_id)) {
      wp_die('No permission.');
    }

    $handover = isset($_POST['handover_checklist']) ? (array) $_POST['handover_checklist'] : [];
    $normalized_handover = [];
    foreach (self::project_handover_items() as $key => $label) {
      $normalized_handover[$key] = !empty($handover[$key]) ? 1 : 0;
    }
    update_post_meta($project_id, '_be_qms_handover_checklist', $normalized_handover);

    $url = add_query_arg(['view'=>'projects','be_action'=>'view','id'=>$project_id], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  /* -------------------------------
   * Profile + Delete + Uploads
   * ------------------------------*/

  public static function handle_save_profile() {
    self::require_staff();
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'be_qms_profile')) {
      wp_die('Invalid nonce.');
    }
    $profile = [
      'company_name' => sanitize_text_field($_POST['company_name'] ?? ''),
      'responsible_person' => sanitize_text_field($_POST['responsible_person'] ?? ''),
      'address' => sanitize_text_field($_POST['address'] ?? ''),
      'phone' => sanitize_text_field($_POST['phone'] ?? ''),
      'email' => sanitize_email($_POST['email'] ?? ''),
      'company_reg' => sanitize_text_field($_POST['company_reg'] ?? ''),
      'mcs_reg' => sanitize_text_field($_POST['mcs_reg'] ?? ''),
      'consumer_code' => sanitize_text_field($_POST['consumer_code'] ?? ''),
    ];
    update_option('be_qms_profile', $profile, false);

    $url = add_query_arg(['view'=>'references','saved'=>'1'], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_delete() {
    self::require_staff();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $kind = isset($_GET['kind']) ? sanitize_key($_GET['kind']) : '';
    if (!$id) wp_die('Missing ID.');

    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'be_qms_delete_'.$id)) {
      wp_die('Invalid nonce.');
    }

    $p = get_post($id);
    if (!$p) wp_die('Not found.');

    if ($kind === 'record' && $p->post_type !== self::CPT_RECORD) wp_die('Wrong type.');
    if ($kind === 'project' && $p->post_type !== self::CPT_PROJECT) wp_die('Wrong type.');

    // Cascade: deleting an employee removes their training records
    if ($kind === 'employee') {
      $tq = new WP_Query([
        'post_type' => self::CPT_TRAINING,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => self::META_EMPLOYEE_LINK,
        'meta_value' => $id,
      ]);
      if ($tq->have_posts()) {
        foreach ($tq->posts as $tid) {
          wp_delete_post((int)$tid, true);
        }
      }
    }

    wp_delete_post($id, true);

    $back_view = ($kind === 'project') ? 'projects' : 'records';
    $url = add_query_arg(['view'=>$back_view], self::portal_url());
    wp_safe_redirect($url);
    exit;
  }

  private static function handle_uploads($field) {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return [];

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $files = $_FILES[$field];
    $att_ids = [];

    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count < 1) return [];

    for ($i=0; $i<$count; $i++) {
      if (empty($files['name'][$i])) continue;

      $file_array = [
        'name' => $files['name'][$i],
        'type' => $files['type'][$i],
        'tmp_name' => $files['tmp_name'][$i],
        'error' => $files['error'][$i],
        'size' => $files['size'][$i],
      ];

      $tmp = $_FILES;
      $_FILES = [$field => $file_array];
      $att_id = media_handle_upload($field, 0);
      $_FILES = $tmp;

      if (!is_wp_error($att_id)) {
        $att_ids[] = (int)$att_id;
      }
    }

    return $att_ids;
  }

  private static function portal_url() {
    $page = get_page_by_path('qms-portal');
    if ($page) return get_permalink($page);
    return home_url('/qms-portal/');
  }

  /* -------------------------------
   * Exports (DOC / Print) + bundled refs
   * ------------------------------*/

  public static function maybe_handle_exports() {
    if (!empty($_GET['be_qms_ref'])) {
      self::require_staff();
      $key = sanitize_key($_GET['be_qms_ref']);
      $map = [
        'manual_docx' => 'assets/documented-procedure-manual-baltic.docx',
        'manual_pdf'  => 'assets/documented-procedure-manual-baltic.pdf',
      ];
      if (empty($map[$key])) wp_die('Unknown reference file');
      $file = plugin_dir_path(__FILE__) . $map[$key];
      if (!file_exists($file)) wp_die('File missing');
      $mime = ($key === 'manual_pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
      header('Content-Type: ' . $mime);
      header('Content-Disposition: attachment; filename="' . basename($file) . '"');
      header('Content-Length: ' . filesize($file));
      readfile($file);
      exit;
    }

    if (!empty($_GET['be_qms_export']) && sanitize_key($_GET['be_qms_export']) === 'print_r07') {
      self::require_staff();
      self::export_print_r07(isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0);
      exit;
    }

    if (empty($_GET['be_qms_export']) || empty($_GET['post'])) return;

    self::require_staff();

    $mode = sanitize_key($_GET['be_qms_export']);
    $id = (int)$_GET['post'];
    $p = get_post($id);
    if (!$p) wp_die('Not found');

    if (!in_array($p->post_type, [self::CPT_RECORD, self::CPT_PROJECT], true)) {
      wp_die('Invalid post type');
    }

    if ($mode === 'doc') {
      self::export_doc($p);
      exit;
    }
    if ($mode === 'print') {
      self::export_print($p);
      exit;
    }
  }

  private static function export_print_r07($employee_id = 0) {
    $title = $employee_id ? ('Training Matrix – ' . (get_the_title($employee_id) ?: 'Employee')) : 'Training Matrix – All Employees';
    $args = [
      'post_type' => self::CPT_TRAINING,
      'post_status' => 'publish',
      'posts_per_page' => 500,
      'orderby' => 'date',
      'order' => 'DESC',
    ];
    if ($employee_id > 0) {
      $args['meta_key'] = self::META_EMPLOYEE_LINK;
      $args['meta_value'] = $employee_id;
    }
    $skills = get_posts($args);

    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($title).'</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:30px;color:#111;} h1{margin:0 0 10px 0;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:8px;font-size:12px;} th{background:#f3f4f6;text-align:left;} .muted{color:#666;font-size:12px;margin-bottom:18px;} @media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<button onclick="window.print()" style="padding:10px 14px;border-radius:10px;border:1px solid #ddd;background:#f8fafc;cursor:pointer">Print / Save as PDF</button>';
    echo '<h1>'.esc_html($title).'</h1>';
    echo '<div class="muted">Generated by Baltic QMS Portal v'.esc_html(self::VERSION).'</div>';

    echo '<table><thead><tr><th>Employee Name</th><th>Course Name</th><th>Date of Course</th><th>Renewal Date</th></tr></thead><tbody>';

    if (!$skills) {
      echo '<tr><td colspan="4">No records.</td></tr>';
    } else {
      foreach ($skills as $skill) {
        $sid = (int) $skill->ID;
        $eid = (int) get_post_meta($sid, self::META_EMPLOYEE_LINK, true);
        $emp = $eid ? get_the_title($eid) : '-';
        $course = get_post_meta($sid, '_be_qms_training_course', true) ?: $skill->post_title;
        $course_date = get_post_meta($sid, '_be_qms_training_date', true);
        $renewal = get_post_meta($sid, '_be_qms_training_renewal', true);
        echo '<tr>';
        echo '<td>'.esc_html($emp).'</td>';
        echo '<td>'.esc_html($course).'</td>';
        echo '<td>'.esc_html(self::format_date_for_display($course_date)).'</td>';
        echo '<td>'.esc_html(self::format_date_for_display($renewal)).'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '</body></html>';
  }

  private static function export_doc($p) {
    $filename = sanitize_file_name($p->post_title) . '.doc';

    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo '<html><head><meta charset="utf-8"><title>'.esc_html($p->post_title).'</title>';
    echo '<style>body{font-family:Arial, sans-serif;font-size:11pt;} h1{font-size:18pt;} h2{font-size:13pt;margin-top:18px;} .muted{color:#666;} table{width:100%;border-collapse:collapse;} td,th{border:1px solid #ddd;padding:8px;vertical-align:top;}</style>';
    echo '</head><body>';
    echo '<h1>'.esc_html($p->post_title).'</h1>';

    if ($p->post_type === self::CPT_RECORD) {
      $terms = get_the_terms($p->ID, self::TAX_RECORD_TYPE);
      $slug = ($terms && !is_wp_error($terms)) ? $terms[0]->slug : '';
      $defs = self::record_type_definitions();
      $type_name = ($slug && isset($defs[$slug])) ? $defs[$slug] : (($terms && !is_wp_error($terms)) ? $terms[0]->name : '-');

      $date = get_post_meta($p->ID, '_be_qms_record_date', true) ?: get_the_date('Y-m-d', $p);
      $display_date = self::format_date_for_display($date);
      $details = get_post_meta($p->ID, '_be_qms_details', true);
      $actions = get_post_meta($p->ID, '_be_qms_actions', true);
      $linked_project = (int) get_post_meta($p->ID, self::META_PROJECT_LINK, true);
      $proj_bits = '';
      if ($linked_project) {
        $proj_bits = ' • Project: ' . esc_html(get_the_title($linked_project) ?: ('Project #'.$linked_project));
      }

      echo '<div class="muted">Type: '.esc_html($type_name).' • Date: '.esc_html($display_date).$proj_bits.'</div>';

      if ($slug === 'r06_customer_complaints') {
        $customer_name = get_post_meta($p->ID, '_be_qms_r06_customer_name', true);
        $address = get_post_meta($p->ID, '_be_qms_r06_address', true);
        $complaint_date = get_post_meta($p->ID, '_be_qms_r06_complaint_date', true);
        $contact_name = get_post_meta($p->ID, '_be_qms_r06_contact_name', true);
        $contact_email = get_post_meta($p->ID, '_be_qms_r06_contact_email', true);
        $contact_phone = get_post_meta($p->ID, '_be_qms_r06_contact_phone', true);
        $contact_mobile = get_post_meta($p->ID, '_be_qms_r06_contact_mobile', true);
        $nature = get_post_meta($p->ID, '_be_qms_r06_nature', true);
        $outcome = get_post_meta($p->ID, '_be_qms_r06_outcome', true);
        $immediate_action = get_post_meta($p->ID, '_be_qms_r06_immediate_action', true);
        $contacted_within_day = get_post_meta($p->ID, '_be_qms_r06_contacted_within_day', true);
        $contacted_reason = get_post_meta($p->ID, '_be_qms_r06_contacted_reason', true);
        $actions_taken = get_post_meta($p->ID, '_be_qms_r06_actions_taken', true);
        $further_action = get_post_meta($p->ID, '_be_qms_r06_further_action', true);
        $customer_satisfied = get_post_meta($p->ID, '_be_qms_r06_customer_satisfied', true);
        $reported_by = get_post_meta($p->ID, '_be_qms_r06_reported_by', true);
        $date_closed = get_post_meta($p->ID, '_be_qms_r06_date_closed', true);
        $display_complaint_date = self::format_date_for_display($complaint_date);
        $display_date_closed = self::format_date_for_display($date_closed);
        $reported_title = get_post_meta($p->ID, '_be_qms_r06_reported_title', true);

        echo '<table>';
        echo '<tr><th>Customer Name</th><td>'.esc_html($customer_name).'</td></tr>';
        echo '<tr><th>Address</th><td>'.wpautop(esc_html($address)).'</td></tr>';
        echo '<tr><th>Date of Complaint</th><td>'.esc_html($display_complaint_date).'</td></tr>';
        echo '<tr><th>Contact&#039;s Name</th><td>'.esc_html($contact_name).'</td></tr>';
        echo '<tr><th>E-mail</th><td>'.esc_html($contact_email).'</td></tr>';
        echo '<tr><th>Telephone</th><td>'.esc_html($contact_phone).'</td></tr>';
        echo '<tr><th>Mobile</th><td>'.esc_html($contact_mobile).'</td></tr>';
        echo '<tr><th>Nature of Complaint</th><td>'.esc_html($nature).'</td></tr>';
        echo '<tr><th>Outcome</th><td>'.esc_html($outcome).'</td></tr>';
        echo '<tr><th>Immediate Action Requested</th><td>'.wpautop(esc_html($immediate_action)).'</td></tr>';
        echo '<tr><th>Customer contacted within 1 working day?</th><td>'.esc_html($contacted_within_day).'</td></tr>';
        echo '<tr><th>If not, why not?</th><td>'.wpautop(esc_html($contacted_reason)).'</td></tr>';
        echo '<tr><th>Actions taken to resolve complaint</th><td>'.wpautop(esc_html($actions_taken)).'</td></tr>';
        echo '<tr><th>Further Action Required</th><td>'.wpautop(esc_html($further_action)).'</td></tr>';
        echo '<tr><th>Is Customer satisfied with result?</th><td>'.esc_html($customer_satisfied).'</td></tr>';
        echo '<tr><th>Reported By</th><td>'.esc_html($reported_by).'</td></tr>';
        echo '<tr><th>Date Closed</th><td>'.esc_html($display_date_closed).'</td></tr>';
        echo '<tr><th>Title</th><td>'.esc_html($reported_title).'</td></tr>';
        echo '</table>';
      } else {
        echo '<h2>Details</h2><div>'.wpautop(esc_html($details)).'</div>';
        if (!empty($actions)) {
          echo '<h2>Actions</h2><div>'.wpautop(esc_html($actions)).'</div>';
        }
      }
    } else {
      $customer = get_post_meta($p->ID, '_be_qms_customer', true);
      $address  = get_post_meta($p->ID, '_be_qms_address', true);
      $pv_kwp   = get_post_meta($p->ID, '_be_qms_pv_kwp', true);
      $bess_kwh = get_post_meta($p->ID, '_be_qms_bess_kwh', true);
      $notes    = get_post_meta($p->ID, '_be_qms_notes', true);

      echo '<table>';
      echo '<tr><th>Customer</th><td>'.esc_html($customer).'</td></tr>';
      echo '<tr><th>Address</th><td>'.esc_html($address).'</td></tr>';
      echo '<tr><th>PV (kWp)</th><td>'.esc_html($pv_kwp).'</td></tr>';
      echo '<tr><th>Battery (kWh)</th><td>'.esc_html($bess_kwh).'</td></tr>';
      echo '</table>';
      if (!empty($notes)) {
        echo '<h2>Notes</h2><div>'.wpautop(esc_html($notes)).'</div>';
      }
    }

    echo '<div class="muted" style="margin-top:24px">Generated by Baltic QMS Portal v'.esc_html(self::VERSION).'</div>';
    echo '</body></html>';
  }

  private static function export_print($p) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($p->post_title).'</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:30px;color:#111;} h1{margin:0 0 8px 0;} .muted{color:#666;font-size:12px;} h2{margin-top:18px;} @media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<button onclick="window.print()" style="padding:10px 14px;border-radius:10px;border:1px solid #ddd;background:#f8fafc;cursor:pointer">Print / Save as PDF</button>';
    echo '<h1>'.esc_html($p->post_title).'</h1>';

    if ($p->post_type === self::CPT_RECORD) {
      $terms = get_the_terms($p->ID, self::TAX_RECORD_TYPE);
      $slug = ($terms && !is_wp_error($terms)) ? $terms[0]->slug : '';
      $defs = self::record_type_definitions();
      $type_name = ($slug && isset($defs[$slug])) ? $defs[$slug] : (($terms && !is_wp_error($terms)) ? $terms[0]->name : '-');

      $date = get_post_meta($p->ID, '_be_qms_record_date', true) ?: get_the_date('Y-m-d', $p);
      $display_date = self::format_date_for_display($date);
      $details = get_post_meta($p->ID, '_be_qms_details', true);
      $actions = get_post_meta($p->ID, '_be_qms_actions', true);

      echo '<div class="muted">Type: '.esc_html($type_name).' • Date: '.esc_html($display_date).'</div>';

      if ($slug === 'r06_customer_complaints') {
        $customer_name = get_post_meta($p->ID, '_be_qms_r06_customer_name', true);
        $address = get_post_meta($p->ID, '_be_qms_r06_address', true);
        $complaint_date = get_post_meta($p->ID, '_be_qms_r06_complaint_date', true);
        $contact_name = get_post_meta($p->ID, '_be_qms_r06_contact_name', true);
        $contact_email = get_post_meta($p->ID, '_be_qms_r06_contact_email', true);
        $contact_phone = get_post_meta($p->ID, '_be_qms_r06_contact_phone', true);
        $contact_mobile = get_post_meta($p->ID, '_be_qms_r06_contact_mobile', true);
        $nature = get_post_meta($p->ID, '_be_qms_r06_nature', true);
        $outcome = get_post_meta($p->ID, '_be_qms_r06_outcome', true);
        $immediate_action = get_post_meta($p->ID, '_be_qms_r06_immediate_action', true);
        $contacted_within_day = get_post_meta($p->ID, '_be_qms_r06_contacted_within_day', true);
        $contacted_reason = get_post_meta($p->ID, '_be_qms_r06_contacted_reason', true);
        $actions_taken = get_post_meta($p->ID, '_be_qms_r06_actions_taken', true);
        $further_action = get_post_meta($p->ID, '_be_qms_r06_further_action', true);
        $customer_satisfied = get_post_meta($p->ID, '_be_qms_r06_customer_satisfied', true);
        $reported_by = get_post_meta($p->ID, '_be_qms_r06_reported_by', true);
        $date_closed = get_post_meta($p->ID, '_be_qms_r06_date_closed', true);
        $display_complaint_date = self::format_date_for_display($complaint_date);
        $display_date_closed = self::format_date_for_display($date_closed);
        $reported_title = get_post_meta($p->ID, '_be_qms_r06_reported_title', true);

        echo '<h2>Customer Details</h2>';
        echo '<ul>';
        echo '<li><strong>Customer Name:</strong> '.esc_html($customer_name).'</li>';
        echo '<li><strong>Address:</strong> '.esc_html($address).'</li>';
        echo '<li><strong>Date of Complaint:</strong> '.esc_html($display_complaint_date).'</li>';
        echo '</ul>';
        echo '<h2>Contact</h2>';
        echo '<ul>';
        echo '<li><strong>Contact&#039;s Name:</strong> '.esc_html($contact_name).'</li>';
        echo '<li><strong>E-mail:</strong> '.esc_html($contact_email).'</li>';
        echo '<li><strong>Telephone:</strong> '.esc_html($contact_phone).'</li>';
        echo '<li><strong>Mobile:</strong> '.esc_html($contact_mobile).'</li>';
        echo '</ul>';
        echo '<h2>Complaint</h2>';
        echo '<ul>';
        echo '<li><strong>Nature of Complaint:</strong> '.esc_html($nature).'</li>';
        echo '<li><strong>Outcome:</strong> '.esc_html($outcome).'</li>';
        echo '</ul>';
        if (!empty($immediate_action)) {
          echo '<h2>Immediate Action Requested</h2><div>'.wpautop(esc_html($immediate_action)).'</div>';
        }
        echo '<h2>Contacted Within 1 Working Day</h2>';
        echo '<ul>';
        echo '<li><strong>Customer contacted within 1 working day?</strong> '.esc_html($contacted_within_day).'</li>';
        echo '<li><strong>If not, why not?</strong> '.esc_html($contacted_reason).'</li>';
        echo '</ul>';
        if (!empty($actions_taken)) {
          echo '<h2>Actions Taken</h2><div>'.wpautop(esc_html($actions_taken)).'</div>';
        }
        if (!empty($further_action)) {
          echo '<h2>Further Action Required</h2><div>'.wpautop(esc_html($further_action)).'</div>';
        }
        echo '<h2>Outcome Confirmation</h2>';
        echo '<ul>';
        echo '<li><strong>Is Customer satisfied with result?</strong> '.esc_html($customer_satisfied).'</li>';
        echo '<li><strong>Reported By:</strong> '.esc_html($reported_by).'</li>';
        echo '<li><strong>Date Closed:</strong> '.esc_html($display_date_closed).'</li>';
        echo '<li><strong>Title:</strong> '.esc_html($reported_title).'</li>';
        echo '</ul>';
      } else {
        echo '<h2>Details</h2><div>'.wpautop(esc_html($details)).'</div>';
        if (!empty($actions)) {
          echo '<h2>Actions</h2><div>'.wpautop(esc_html($actions)).'</div>';
        }
      }
    } else {
      $customer = get_post_meta($p->ID, '_be_qms_customer', true);
      $address  = get_post_meta($p->ID, '_be_qms_address', true);
      $pv_kwp   = get_post_meta($p->ID, '_be_qms_pv_kwp', true);
      $bess_kwh = get_post_meta($p->ID, '_be_qms_bess_kwh', true);
      $notes    = get_post_meta($p->ID, '_be_qms_notes', true);

      echo '<div class="muted">Project evidence</div>';
      echo '<h2>Summary</h2>';
      echo '<ul>';
      echo '<li><strong>Customer:</strong> '.esc_html($customer).'</li>';
      echo '<li><strong>Address:</strong> '.esc_html($address).'</li>';
      echo '<li><strong>PV (kWp):</strong> '.esc_html($pv_kwp).'</li>';
      echo '<li><strong>Battery (kWh):</strong> '.esc_html($bess_kwh).'</li>';
      echo '</ul>';
      if (!empty($notes)) {
        echo '<h2>Notes</h2><div>'.wpautop(esc_html($notes)).'</div>';
      }

      $att_ids  = get_post_meta($p->ID, '_be_qms_evidence', true);
      if (!is_array($att_ids)) $att_ids = [];
      echo '<h2>Evidence list</h2>';
      if (!$att_ids) {
        echo '<div class="muted">No evidence files uploaded.</div>';
      } else {
        echo '<ol>';
        foreach ($att_ids as $aid) {
          $url = wp_get_attachment_url($aid);
          $name = get_the_title($aid);
          echo '<li>'.esc_html($name ?: basename((string)$url)).'</li>';
        }
        echo '</ol>';
      }
    }

    echo '<div class="muted" style="margin-top:24px">Generated by Baltic QMS Portal v'.esc_html(self::VERSION).'</div>';
    echo '</body></html>';
  }
}

register_activation_hook(__FILE__, ['BE_QMS_Portal', 'on_activate']);
add_action('plugins_loaded', ['BE_QMS_Portal', 'init']);
