<?php
/**
 * Integra Sentinel — struttura delle pagine di amministrazione, centro colli di bottiglia,
 * vista per prodotto e comandi. Il Master resta in sola lettura verso i siti: qui si leggono dati,
 * si pianifica e si documenta, non si interviene mai sui Client.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Admin {

    public static function pages() {
        return array(
            'integra-sentinel' => 'Panoramica',
            'integra-sentinel-bottlenecks' => 'Colli di bottiglia',
            'integra-sentinel-fleet' => 'Plugin e temi',
            'integra-sentinel-history' => 'Storico',
            'integra-sentinel-reports' => 'Report clienti',
            'integra-sentinel-settings' => 'Impostazioni',
        );
    }

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('admin_post_integra_sentinel_admin', array(__CLASS__, 'handle'));
        add_action('admin_post_integra_sentinel_document', array(__CLASS__, 'document'));
        add_action('admin_post_integra_sentinel_csv', array(__CLASS__, 'csv'));
    }

    public static function menu() {
        foreach (self::pages() as $slug => $title) {
            add_submenu_page('integra-sentinel', $title . ' · Integra Sentinel', $title, Integra_Sentinel_Settings::CAP_VIEW, $slug,
                $slug === 'integra-sentinel' ? array('Integra_Sentinel_Master', 'page') : array(__CLASS__, 'render'));
        }
    }

    public static function url($page, $args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . $page));
    }

    private static function e($value) { return esc_html((string) $value); }

    private static function badge($text, $tone = 'muted') {
        return '<span class="sn-badge sn-' . esc_attr($tone) . '">' . self::e($text) . '</span>';
    }

    public static function minutes_text($minutes) {
        $minutes = (int) $minutes;
        if ($minutes < 60) { return $minutes . ' min'; }
        $hours = $minutes / 60;
        return ($hours >= 10 ? number_format_i18n($hours, 0) : number_format_i18n($hours, 1)) . ' h';
    }

    /** Messaggi dopo i comandi: [testo, tipo di notice]. */
    public static function messages() {
        return array(
            'waiver' => array('Eccezione approvata: l’elemento esce dai conteggi e dagli allarmi fino alla scadenza.', 'success'),
            'verified' => array('Segnato come aggiornato: tornerà nell’elenco per un nuovo controllo alla data scelta.', 'success'),
            'tested' => array('Segnato come provato: torna nell’elenco solo se cambiano le versioni coinvolte.', 'success'),
            'waiting' => array('Segnato in attesa dell’aggiornamento del fornitore.', 'success'),
            'waiver_removed' => array('Segnatura rimossa: l’elemento torna nell’elenco.', 'success'),
            'envato' => array('Token Envato salvato e cifrato. Gli acquisti vengono letti adesso.', 'success'),
            'envato_removed' => array('Token Envato rimosso.', 'success'),
            'envato_ok' => array('Acquisti Envato aggiornati.', 'success'),
            'envato_fail' => array('Lettura degli acquisti Envato non riuscita: vedi il messaggio nella sezione Envato.', 'error'),
            'webhooks' => array('Canali di notifica salvati.', 'success'),
            'webhook_test' => array('Messaggio di prova inviato ai canali configurati.', 'success'),
            'webhook_fail' => array('Nessun canale ha accettato il messaggio: controlla URL e token.', 'error'),
            'saved' => array('Impostazioni salvate.', 'success'),
            'site_checklist' => array('Checklist di manutenzione salvata.', 'success'),
            'pair' => array('Codice di abbinamento generato: copialo adesso, è valido 30 minuti.', 'success'),
            'probe' => array('Controllo di raggiungibilità eseguito.', 'success'),
            'report_sent' => array('Report inviato.', 'success'),
            'report_fail' => array('Report non inviato: controlla l’indirizzo del cliente e la configurazione email.', 'error'),
            'ai_saved' => array('Impostazioni dell’intelligenza artificiale salvate.', 'success'),
            'ai_ok' => array('Collegamento riuscito: il modello risponde correttamente.', 'success'),
            'ai_fail' => array('Collegamento non riuscito: vedi il messaggio nella sezione Intelligenza artificiale.', 'error'),
            'draft_ai' => array('Bozza scritta dall’IA: rileggila, correggila se serve e approvala prima di inviarla.', 'success'),
            'draft_ai_fail' => array('L’IA non ha prodotto la bozza: vedi il messaggio sopra il testo.', 'error'),
            'draft_saved' => array('Resoconto salvato.', 'success'),
            'drafts_queued' => array('Bozze in preparazione: l’IA le scrive in sottofondo, un paio alla volta. Riceverai un avviso quando sono pronte.', 'success'),
            'drafts_none' => array('Nessuna bozza da preparare: tutti i siti con un inventario hanno già un resoconto per questo mese (o l’IA non è configurata).', 'warning'),
            'drafts_cancelled' => array('Preparazione delle bozze interrotta. Le bozze già scritte restano.', 'success'),
            'component_notes' => array('Spiegazioni salvate: compariranno nei report di questo sito.', 'success'),
            'event_notes' => array('Motivi salvati: verranno usati nel resoconto per il cliente.', 'success'),
            'event_notes_none' => array('Nessun motivo modificato: non c’era niente da salvare.', 'info'),
            'event_notes_partial' => array('Alcuni motivi non sono stati salvati: ricarica la pagina e controlla.', 'error'),
            'draft_approved' => array('Resoconto approvato: ora compare nel report mensile e nell’email al cliente.', 'success'),
            'daily' => array('Lavori giornalieri eseguiti: andamenti ricalcolati e archivio ripulito.', 'success'),
            'intel' => array('Dati da WordPress.org ed Envato aggiornati (a lotti: ripeti se il parco è grande).', 'success'),
            'invalid' => array('Dati non validi: nessuna modifica effettuata.', 'error'),
            'denied' => array('Operazione riservata agli amministratori.', 'error'),
            'busy' => array('Database temporaneamente occupato: nessuna modifica effettuata, riprova.', 'error'),
        );
    }

    /** Intestazione comune delle pagine Sentinel. */
    public static function open($current, $right = '') {
        echo '<div class="wrap integra-sentinel sn-app">';
        echo '<header class="sn-header"><div class="sn-brand-mark" aria-hidden="true">◆</div><div class="sn-brand-copy"><h1>Integra Sentinel <span class="sn-version">v'
            . self::e(Integra_Sentinel_Master::VERSION) . '</span></h1><p>Security, updates &amp; integrity monitoring · <a href="https://integracorporate.it/" target="_blank" rel="noopener">Integra</a></p></div><div class="sn-header-right">' . $right . '</div></header>';
        self::nav($current);
        self::notice();
    }

    public static function close() { echo '</div>'; }

    public static function nav($current) {
        echo '<nav class="sn-nav" aria-label="Sezioni di Integra Sentinel">';
        foreach (self::pages() as $slug => $title) {
            $on = $slug === $current;
            echo '<a class="sn-nav-link' . ($on ? ' is-current' : '') . '" href="' . esc_url(self::url($slug)) . '"' . ($on ? ' aria-current="page"' : '') . '>' . self::e($title) . '</a>';
        }
        echo '</nav>';
    }

    public static function notice() {
        $msg = isset($_GET['sentinel_msg']) ? sanitize_key(wp_unslash($_GET['sentinel_msg'])) : '';
        $messages = self::messages();
        if (isset($messages[$msg])) { echo '<div class="notice inline notice-' . esc_attr($messages[$msg][1]) . '"><p>' . self::e($messages[$msg][0]) . '</p></div>'; }
    }

    public static function form_start($cmd, $return, $extra = array(), $class = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sn-form ' . esc_attr($class) . '">';
        wp_nonce_field('integra_sentinel_admin');
        echo '<input type="hidden" name="action" value="integra_sentinel_admin"><input type="hidden" name="cmd" value="' . esc_attr($cmd) . '"><input type="hidden" name="_sn_return" value="' . esc_attr($return) . '">';
        foreach ($extra as $key => $value) { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">'; }
    }

    public static function render() {
        if (!Integra_Sentinel_Settings::can_view()) { return; }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        switch ($page) {
            case 'integra-sentinel-bottlenecks': self::page_bottlenecks(); break;
            case 'integra-sentinel-fleet': self::page_fleet(); break;
            case 'integra-sentinel-history': Integra_Sentinel_Admin_Pages::history(); break;
            case 'integra-sentinel-reports': Integra_Sentinel_Admin_Pages::reports(); break;
            case 'integra-sentinel-settings': Integra_Sentinel_Admin_Pages::settings(); break;
        }
    }

    /* ---------- Componenti della panoramica ---------- */

    /** Riga di indicatori: cosa si può aggiornare subito e cosa no. */
    public static function readiness($fleet) {
        $k = $fleet['kpi']; $s = $fleet['kpi_sites'];
        $center = self::url('integra-sentinel-bottlenecks');
        echo '<div class="sn-ready" role="list">';
        self::ready_tile('Aggiornabili subito', $k['ready'], 'su ' . $s['ready'] . ' siti, senza blocchi noti', 'ok', '#sn-sites');
        self::ready_tile('Aggiornamenti bloccati', $k['blocked'], $k['security'] ? $k['security'] . ' trattengono patch di sicurezza' : 'trattenuti, bloccati o non scaricabili', 'danger', $center . '#sn-kind-security');
        self::ready_tile('Licenze da sistemare', $k['license'], 'su ' . $s['license'] . ' siti', 'premium', $center . '#sn-kind-license');
        self::ready_tile('Premium da aggiornare a mano', $k['manual'], 'versioni nuove fuori da WordPress', 'premium', $center . '#sn-kind-manual');
        self::ready_tile('Punti ciechi', $k['blind'], 'premium senza versione di riferimento', 'muted', $center . '#sn-kind-blind');
        self::ready_tile('Lavoro stimato', self::minutes_text($k['minutes']), 'per allineare tutto il parco', 'brand', $center);
        echo '</div>';
    }

    private static function ready_tile($label, $value, $sub, $tone, $href) {
        echo '<a class="sn-ready-tile sn-ready-' . esc_attr($tone) . '" role="listitem" href="' . esc_url($href) . '"><span class="sn-ready-label">' . self::e($label) . '</span><span class="sn-ready-value">'
            . self::e(is_int($value) ? number_format_i18n($value) : $value) . '</span><span class="sn-ready-sub">' . self::e($sub) . '</span></a>';
    }

    /** Tipi di problema che riguardano i plugin fuori repository: si segnano come aggiornati e si ricontrollano. */
    public static function recheck_kinds() { return array('blind', 'manual', 'bundled'); }

    /** Pagina Colli di bottiglia: un blocco per causa, dentro una riga per sito e problema. */
    public static function bottleneck_center($fleet) {
        $kinds = Integra_Sentinel_Fleet::kinds();
        $total = (int) $fleet['total'];
        echo '<section class="sn-panel sn-center" id="sn-center" data-sn-center>';
        echo '<div class="sn-section-head"><div><h3>Tutti i colli di bottiglia</h3><p>Divisi per causa. Ogni riga è un sito: prima il sito, poi il componente e cosa fare.</p></div>';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=integra_sentinel_csv'), 'integra_sentinel_csv')) . '">Esporta CSV</a></div>';
        if (!$total) {
            echo '<div class="sn-all-clear"><span aria-hidden="true">✓</span><p><strong>Nessun collo di bottiglia</strong>Nei dati disponibili nessun aggiornamento è bloccato da licenze, addon, server o versioni.</p></div></section>';
            return;
        }
        echo '<div class="sn-center-tools"><label class="sn-search sn-center-search"><span aria-hidden="true">⌕</span><input type="search" data-sn-center-search placeholder="Cerca un sito, un plugin o un tema…" aria-label="Cerca nei colli di bottiglia"></label></div>';
        $manage = Integra_Sentinel_Settings::can_manage();
        foreach ($fleet['kinds'] as $kind => $rows) {
            $site_ids = array_unique(array_column($rows, 'site_id'));
            echo '<details class="sn-kind" id="sn-kind-' . esc_attr($kind) . '" data-sn-kind="' . esc_attr($kind) . '" open><summary><span class="sn-kind-title"><strong>' . self::e($kinds[$kind]['label'] ?? $kind) . '</strong><small>'
                . self::e($kinds[$kind]['hint'] ?? '') . '</small></span><span class="sn-kind-count">' . count($rows) . ' · ' . count($site_ids) . ' ' . (count($site_ids) === 1 ? 'sito' : 'siti') . '</span></summary><div class="sn-kind-body">';
            foreach ($rows as $b) { self::item_row($b, false, $manage); }
            echo '</div></details>';
        }
        echo '<p class="sn-no-results" data-sn-center-empty hidden>Nessun elemento corrisponde alla ricerca.</p>';
        if ($fleet['waived']) {
            echo '<p class="sn-hint">' . count($fleet['waived']) . ' elementi tolti dall’elenco (eccezioni, aggiornati a mano, provati) · <a href="#sn-waivers">vedi</a></p>';
        }
        echo '</section>';
    }

    /** Panoramica: le righe piu' urgenti di tutto il parco, qualunque sia la causa. */
    public static function urgent_list($fleet, $limit = 5) {
        $total = (int) $fleet['total'];
        echo '<section class="sn-panel sn-center"><div class="sn-section-head"><div><h3>Cosa sistemare per primo</h3><p>Ciò che impedisce di aggiornare i siti, dal più urgente.</p></div>';
        if ($total) { echo '<a class="button" href="' . esc_url(self::url('integra-sentinel-bottlenecks')) . '">' . ($total > $limit ? 'Vedi tutti (' . $total . ') →' : 'Gestisci →') . '</a>'; }
        echo '</div>';
        if (!$total) {
            echo '<div class="sn-all-clear"><span aria-hidden="true">✓</span><p><strong>Niente da sistemare</strong>Nei dati disponibili nessun aggiornamento è bloccato da licenze, plugin, server o versioni.</p></div></section>';
            return;
        }
        foreach (Integra_Sentinel_Fleet::top_items($fleet, $limit) as $b) { self::item_row($b, true, false); }
        echo '</section>';
    }

    public static function site_anchor_url($site_id, $item) {
        $node = (string) ($item['node'] ?? '');
        $anchor = ($node !== '' && strpos($node, 'env:') !== 0) ? 'sn-plugin-' . sanitize_html_class($node) : 'sn-bottlenecks';
        return Integra_Sentinel_Master::sn_admin_url($site_id) . '#' . $anchor;
    }

    /** Una riga: sito, componente, problema, cosa fare e le azioni possibili. */
    private static function item_row($b, $compact, $manage) {
        $mark = $b['mark'] ?? null;
        $waiting = $mark && $mark['type'] === 'waiting' && !empty($mark['live']);
        $name = (string) $b['subjectName'];
        $text = function_exists('mb_strtolower') ? mb_strtolower($b['site_label'] . ' ' . $name . ' ' . $b['title']) : strtolower($b['site_label'] . ' ' . $name . ' ' . $b['title']);
        echo '<article class="sn-group sn-sev-' . esc_attr($waiting ? 'low' : $b['severity']) . ($waiting ? ' is-waiting' : '') . '" id="sn-i-' . esc_attr($b['key']) . '" data-sn-text="' . esc_attr($text) . '">';
        echo '<div class="sn-group-main"><div class="sn-group-title"><a class="sn-item-site" href="' . esc_url(self::site_anchor_url($b['site_id'], $b)) . '">' . self::e($b['site_label']) . '</a><span class="sn-item-sep" aria-hidden="true">›</span><strong>' . self::e($name) . '</strong>';
        if (!empty($b['security'])) { echo self::badge('Sicurezza', 'danger'); }
        echo $waiting ? self::badge('In attesa di aggiornamento', 'muted') : self::badge(Integra_Sentinel_Fleet::severity_label($b['severity']), Integra_Sentinel_Fleet::severity_tone($b['severity']));
        if ($compact) { echo self::badge(Integra_Sentinel_Fleet::kind_label($b['kind']), 'muted'); }
        echo '</div>';
        if ($b['title'] !== $name) { echo '<p class="sn-group-sub">' . self::e($b['title']) . '</p>'; }
        echo '<p>' . self::e($b['detail']) . '</p><p class="sn-group-action"><b>Cosa fare:</b> ' . self::e($b['action']) . '</p>';
        if ($mark) {
            $when = wp_date('d/m/Y', (int) ($mark['at'] ?? 0));
            $note = array(
                'verified' => 'Segnato come aggiornato all’ultima versione il ' . $when . ': è ora di ricontrollare.',
                'tested' => 'Provato il ' . $when . ' con versioni diverse da quelle di adesso: da riprovare.',
                'waiting' => 'In attesa dell’aggiornamento del fornitore dal ' . $when . '. Esce da solo dall’elenco quando arriva un aggiornamento che risolve.',
            )[$mark['type']] ?? '';
            if ($note !== '') { echo '<p class="sn-item-mark">' . self::e($note) . '</p>'; }
        }
        echo '</div><div class="sn-group-side"><small>~' . self::e(self::minutes_text((int) $b['minutes'])) . '</small>';
        if ($compact) { echo '<a class="sn-linkbtn" href="' . esc_url(self::url('integra-sentinel-bottlenecks') . '#sn-i-' . $b['key']) . '">Gestisci</a>'; }
        echo '</div>';
        if ($manage) { self::item_actions($b, $waiting); }
        echo '</article>';
    }

    private static function item_actions($b, $waiting) {
        $base = array('site_id' => $b['site_id'], 'kind' => $b['kind'], 'subject' => $b['subject'], 'sig' => $b['sig'], 'name' => $b['subjectName'], 'ver' => (string) ($b['ver'] ?? ''), '_sn_anchor' => 'sn-kind-' . $b['kind']);
        echo '<div class="sn-item-actions">';
        if (in_array($b['kind'], self::recheck_kinds(), true)) {
            $days = (int) (Integra_Sentinel_Settings::get('policies')['recheck_days'] ?? 60);
            self::form_start('mark_verified', 'integra-sentinel-bottlenecks', $base, 'sn-inline-form');
            echo '<button type="submit" class="button button-small button-primary">Aggiornato all’ultima versione</button> <label>ricontrolla tra <select name="days">';
            foreach (array(30, 60, 90, 180) as $d) { echo '<option value="' . $d . '"' . selected($days, $d, false) . '>' . $d . ' giorni</option>'; }
            echo '</select></label></form>';
        }
        if ($b['kind'] === 'unknown') {
            self::form_start('mark_tested', 'integra-sentinel-bottlenecks', $base, 'sn-inline-form');
            echo '<button type="submit" class="button button-small button-primary">Provato: funziona</button></form>';
            if ($waiting) {
                self::form_start('waiver_remove', 'integra-sentinel-bottlenecks', array('id' => $b['mark']['id'], '_sn_anchor' => 'sn-i-' . $b['key']), 'sn-inline-form');
                echo '<button type="submit" class="button button-small">Togli l’attesa</button></form>';
            } else {
                self::form_start('mark_waiting', 'integra-sentinel-bottlenecks', $base, 'sn-inline-form');
                echo '<button type="submit" class="button button-small">In attesa di aggiornamento</button></form>';
            }
        }
        echo '<details class="sn-item-waiver"><summary>Eccezione…</summary>';
        self::form_start('waiver_add', 'integra-sentinel-bottlenecks', $base);
        echo '<input type="text" name="reason" maxlength="200" placeholder="Motivo (facoltativo)" aria-label="Motivo dell’eccezione"> <label>fino al <input type="date" name="until" required value="' . esc_attr(wp_date('Y-m-d', strtotime('+90 days'))) . '"></label> <label><input type="checkbox" name="fleet" value="1"> su tutti i siti</label> ';
        echo '<button type="submit" class="button button-small">Togli dall’elenco</button></form></details>';
        echo '</div>';
    }

    /** Andamenti degli ultimi 30 giorni: riquadri con valore, variazione e sparkline (con tabella dei dati). */
    public static function trends($fleet) {
        $since = wp_date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
        $days = Integra_Sentinel_Store::metrics_since($since);
        if (count($days) < 2) {
            echo '<section class="sn-panel sn-trends"><div class="sn-section-head"><div><h3>Andamenti</h3><p>Le metriche si registrano una volta al giorno: l’andamento compare dal secondo giorno di dati.</p></div></div></section>';
            return;
        }
        $series = array('bottlenecks' => array(), 'blocked' => array(), 'vulns' => array());
        foreach ($days as $day => $rows) {
            foreach ($series as $key => $_) {
                $sum = 0;
                foreach ($rows as $data) { $sum += (int) ($data[$key] ?? 0); }
                $series[$key][] = array($day, $sum);
            }
        }
        $current = array('bottlenecks' => (int) $fleet['kpi']['bottlenecks'], 'blocked' => (int) $fleet['kpi']['blocked'], 'vulns' => 0);
        foreach ($fleet['per_site'] as $id => $a) {
            $site = Integra_Sentinel_Master::sites()[$id] ?? array();
            $current['vulns'] += count(Integra_Sentinel_Master::sn_group_findings($site['findings'] ?? array()));
        }
        $labels = array('bottlenecks' => 'Colli di bottiglia', 'blocked' => 'Aggiornamenti bloccati', 'vulns' => 'Componenti vulnerabili');
        echo '<section class="sn-panel sn-trends"><div class="sn-section-head"><div><h3>Andamenti</h3><p>Ultimi 30 giorni, una misura al giorno. Scendere è il risultato atteso del lavoro di manutenzione.</p></div></div><div class="sn-trend-grid">';
        foreach ($series as $key => $points) {
            $first = (int) $points[0][1];
            $delta = $current[$key] - $first;
            $tone = $delta > 0 ? 'worse' : ($delta < 0 ? 'better' : 'flat');
            $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '■');
            $points[] = array(wp_date('Y-m-d'), $current[$key]);
            echo '<div class="sn-trend"><span class="sn-trend-label">' . self::e($labels[$key]) . '</span><span class="sn-trend-value">' . self::e(number_format_i18n($current[$key])) . '</span>';
            echo '<span class="sn-trend-delta sn-delta-' . $tone . '"><span aria-hidden="true">' . $arrow . '</span> ' . ($delta > 0 ? '+' : '') . self::e(number_format_i18n($delta)) . ' rispetto al ' . self::e(wp_date('d/m', strtotime($points[0][0] . ' 12:00:00'))) . '</span>';
            echo self::sparkline($points, $labels[$key]);
            echo '</div>';
        }
        echo '</div><details class="sn-trend-table"><summary>Vedi i dati in tabella</summary><table class="sn-simple-table"><thead><tr><th>Giorno</th>';
        foreach ($labels as $label) { echo '<th>' . self::e($label) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach (array_keys($days) as $i => $day) {
            echo '<tr><td>' . self::e(wp_date('d/m/Y', strtotime($day . ' 12:00:00'))) . '</td>';
            foreach (array_keys($labels) as $key) { echo '<td>' . (int) $series[$key][$i][1] . '</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table></details></section>';
    }

    /** Sparkline SVG: linea di contesto grigia, ultimo punto nel colore di accento, mirino e tooltip via JS. */
    private static function sparkline($points, $label) {
        $n = count($points);
        $w = 240; $h = 48; $pad = 6;
        $max = max(1, max(array_map(function($p) { return (int) $p[1]; }, $points)));
        $xy = array();
        foreach ($points as $i => $p) {
            $x = $pad + ($w - 2 * $pad) * ($n > 1 ? $i / ($n - 1) : 0);
            $y = $h - $pad - ($h - 2 * $pad) * ((int) $p[1] / $max);
            $xy[] = array(round($x, 1), round($y, 1));
        }
        $line = '';
        foreach ($xy as $i => $c) { $line .= ($i ? ' L' : 'M') . $c[0] . ' ' . $c[1]; }
        $last = end($xy);
        $data = array();
        foreach ($points as $i => $p) { $data[] = array('d' => wp_date('d/m', strtotime($p[0] . ' 12:00:00')), 'v' => (int) $p[1], 'x' => $xy[$i][0], 'y' => $xy[$i][1]); }
        return '<div class="sn-spark" data-sn-spark="' . esc_attr(wp_json_encode($data)) . '"><svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" role="img" tabindex="0" aria-label="' . esc_attr($label . ': andamento degli ultimi 30 giorni, usa le frecce per scorrere i valori') . '">'
            . '<line class="sn-spark-base" x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '"></line>'
            . '<path class="sn-spark-line" d="' . esc_attr($line) . '"></path>'
            . '<line class="sn-spark-cross" x1="0" y1="' . $pad . '" x2="0" y2="' . ($h - $pad) . '" hidden></line>'
            . '<circle class="sn-spark-dot" cx="' . $last[0] . '" cy="' . $last[1] . '" r="4"></circle>'
            . '<circle class="sn-spark-hover" cx="0" cy="0" r="4" hidden></circle></svg><div class="sn-spark-tip" role="status" hidden></div></div>';
    }

    /* ---------- Pagina: colli di bottiglia ---------- */

    private static function page_bottlenecks() {
        $fleet = Integra_Sentinel_Fleet::aggregate();
        self::open('integra-sentinel-bottlenecks', self::badge($fleet['total'] . ' elementi · ' . $fleet['sites'] . ' siti', 'brand'));
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">COLLI DI BOTTIGLIA</p><h2>Cosa impedisce di aggiornare</h2><p>Licenze, premium da aggiornare a mano, addon, server, versioni e punti ciechi di tutto il parco. Per ogni riga puoi segnare cosa hai fatto o togliere il problema dall’elenco con un’eccezione.</p></div></section>';
        self::readiness($fleet);
        self::bottleneck_center($fleet);
        $waivers = Integra_Sentinel_Settings::waivers();
        $types = Integra_Sentinel_Settings::mark_types();
        $sites = Integra_Sentinel_Master::sites();
        echo '<section class="sn-panel" id="sn-waivers"><div class="sn-section-head"><div><h3>Eccezioni e verifiche</h3><p>Cosa hai segnato: le eccezioni valgono fino alla data scelta, gli aggiornamenti a mano tornano da ricontrollare, le prove valgono finché non cambiano le versioni.</p></div><span class="sn-counter">' . count($waivers) . '</span></div>';
        if (!$waivers) { echo '<p class="sn-muted">Niente di segnato.</p>'; }
        else {
            $today = wp_date('Y-m-d');
            uasort($waivers, function($a, $b) { return ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0)); });
            echo '<div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Sito</th><th>Componente</th><th>Causa</th><th>Segnato come</th><th>Validità</th><th>Da</th><th></th></tr></thead><tbody>';
            foreach ($waivers as $w) {
                $type = (string) ($w['type'] ?? 'waiver');
                $where = ($w['site_id'] ?? '') === '*' ? 'Tutti i siti' : (string) ($sites[$w['site_id']]['label'] ?? 'Sito rimosso');
                $until = (string) ($w['until'] ?? '');
                $validity = in_array($type, array('tested', 'waiting'), true) ? 'Finché non cambiano le versioni'
                    : ($until < $today ? self::badge($type === 'verified' ? 'Da ricontrollare' : 'Scaduta', 'warn') : ($type === 'verified' ? 'Ricontrollo il ' : 'Fino al ') . wp_date('d/m/Y', strtotime($until . ' 12:00:00')));
                echo '<tr><td>' . self::e($where) . '</td><td>' . self::e(($w['name'] ?? '') !== '' ? $w['name'] : $w['subject']) . '</td><td>' . self::e(Integra_Sentinel_Fleet::kind_label($w['kind'])) . '</td><td>'
                    . self::e($types[$type] ?? $type) . (($w['reason'] ?? '') !== '' && $type === 'waiver' ? '<br><small>' . self::e($w['reason']) . '</small>' : '') . '</td><td>'
                    . (strpos($validity, '<span') === 0 ? $validity : self::e($validity)) . '</td><td>' . self::e(($w['by'] ?? '') . (!empty($w['at']) ? ' · ' . wp_date('d/m/Y', (int) $w['at']) : '')) . '</td><td>';
                if (Integra_Sentinel_Settings::can_manage()) {
                    self::form_start('waiver_remove', 'integra-sentinel-bottlenecks', array('id' => $w['id'], '_sn_anchor' => 'sn-waivers'));
                    echo '<button class="button button-small" type="submit">Rimuovi</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
        self::close();
    }

    /* ---------- Pagina: plugin e temi del parco ---------- */

    private static function page_fleet() {
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $products = Integra_Sentinel_Fleet::products($fleet);
        $key = isset($_GET['product']) ? sanitize_text_field(wp_unslash($_GET['product'])) : '';
        if ($key !== '' && isset($products[$key])) {
            self::open('integra-sentinel-fleet', '<a class="button" href="' . esc_url(self::url('integra-sentinel-fleet')) . '">← Tutti i plugin e temi</a>');
            self::product_detail($products[$key]);
            self::close();
            return;
        }
        $by_type = array('plugin' => array(), 'theme' => array());
        foreach ($products as $pkey => $p) { $by_type[$p['type']][$pkey] = $p; }
        $sites = array();
        foreach (Integra_Sentinel_Master::sites() as $id => $site) { if (isset($fleet['per_site'][$id])) { $sites[$id] = (string) $site['label']; } }
        uasort($sites, 'strcasecmp');
        self::open('integra-sentinel-fleet', self::badge(count($by_type['plugin']) . ' plugin · ' . count($by_type['theme']) . ' temi', 'brand'));
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">PLUGIN E TEMI DEL PARCO</p><h2>Dove si trova ogni plugin e ogni tema</h2><p>Su quali siti è installato, con quali versioni e cosa blocca l’allineamento. Accendi o spegni i siti per vedere solo quelli che ti interessano; apri un prodotto per il dettaglio sito per sito.</p></div></section>';
        echo '<section class="sn-section sn-fleet" data-sn-fleet>';
        echo '<div class="sn-fleet-sites"><div class="sn-fleet-sites-head"><strong>Siti</strong> <span class="sn-hint" data-sn-fleet-count>' . count($sites) . ' di ' . count($sites) . ' attivi</span> <button type="button" class="sn-linkbtn" data-sn-fleet-all="1">Tutti</button> · <button type="button" class="sn-linkbtn" data-sn-fleet-all="0">Nessuno</button></div><div class="sn-chips" role="group" aria-label="Siti da includere">';
        foreach ($sites as $id => $label) {
            echo '<button type="button" class="sn-chip is-selected" data-sn-fleet-site="' . esc_attr($id) . '" aria-pressed="true">' . self::e($label) . '</button>';
        }
        echo '</div></div>';
        echo '<div class="sn-fleet-bar"><div class="sn-tabs" role="tablist" aria-label="Plugin o temi">';
        foreach (array('plugin' => 'Plugin', 'theme' => 'Temi') as $type => $label) {
            $on = $type === 'plugin';
            echo '<button type="button" class="sn-tab' . ($on ? ' is-selected' : '') . '" role="tab" id="sn-ftab-' . $type . '" aria-controls="sn-fpanel-' . $type . '" aria-selected="' . ($on ? 'true' : 'false') . '" data-sn-ftab="' . $type . '">' . self::e($label) . ' <span data-sn-ftab-count="' . $type . '">' . count($by_type[$type]) . '</span></button>';
        }
        echo '</div><label class="sn-search"><span aria-hidden="true">⌕</span><input type="search" data-sn-fleet-search placeholder="Cerca un plugin o un tema…" aria-label="Cerca plugin e temi"></label></div>';
        foreach ($by_type as $type => $list) {
            echo '<div class="sn-table-wrap" id="sn-fpanel-' . $type . '" role="tabpanel" aria-labelledby="sn-ftab-' . $type . '"' . ($type === 'plugin' ? '' : ' hidden') . '><table class="sn-simple-table sn-products"><thead><tr><th>' . ($type === 'plugin' ? 'Plugin' : 'Tema') . '</th><th>Siti</th><th>Versioni installate</th><th>Ultima nota</th><th>Da aggiornare</th><th>Problemi</th></tr></thead><tbody>';
            foreach ($list as $pkey => $p) { self::fleet_row($pkey, $p); }
            echo '</tbody></table><p class="sn-no-results" data-sn-fleet-empty hidden>Nessun ' . ($type === 'plugin' ? 'plugin' : 'tema') . ' sui siti scelti corrisponde alla ricerca.</p></div>';
        }
        echo '</section>';
        self::close();
    }

    /** Riga di un prodotto: le colonne sono ricalcolate dal JS quando si spengono dei siti, a partire dai dati per sito. */
    private static function fleet_row($pkey, $p) {
        $data = array(); $versions = array(); $behind = 0; $issues = array('vuln' => 0, 'blocked' => 0, 'license' => 0, 'blind' => 0);
        foreach ($p['sites'] as $id => $s) {
            $is_behind = $p['latest'] !== '' && Integra_Sentinel_Dependencies::valid_version((string) $s['ver']) && version_compare(ltrim($s['ver'], 'v'), ltrim($p['latest'], 'v'), '<');
            $row = array('l' => $s['label'], 'v' => (string) $s['ver'], 'b' => $is_behind ? 1 : 0, 'x' => !empty($s['vuln']['count']) ? 1 : 0,
                'k' => in_array($s['status'], array('blocked', 'held', 'bottleneck'), true) ? 1 : 0, 'c' => $s['status'] === 'license' ? 1 : 0, 'u' => $s['status'] === 'blind' ? 1 : 0);
            $data[$id] = $row;
            if ($s['ver'] !== '') { $versions[$s['ver']] = true; }
            $behind += $row['b']; $issues['vuln'] += $row['x']; $issues['blocked'] += $row['k']; $issues['license'] += $row['c']; $issues['blind'] += $row['u'];
        }
        uksort($versions, function($a, $b) { return version_compare(ltrim($a, 'v'), ltrim($b, 'v')); });
        $vlist = array_keys($versions);
        $names = array_column($p['sites'], 'label');
        sort($names, SORT_STRING | SORT_FLAG_CASE);
        echo '<tr data-sn-name="' . esc_attr(strtolower($p['name'] . ' ' . $p['slug'])) . '" data-sn-sites="' . esc_attr(wp_json_encode($data)) . '"><td><a href="' . esc_url(self::url('integra-sentinel-fleet', array('product' => $pkey))) . '"><strong>' . self::e($p['name']) . '</strong></a> '
            . ($p['envato'] ? self::badge('Envato', 'premium') : ($p['external'] ? self::badge('Fuori repository', 'premium') : '')) . '<br><small><code>' . self::e($p['slug']) . '</code></small></td>';
        echo '<td><strong data-sn-col="n">' . count($p['sites']) . '</strong><br><small data-sn-col="names">' . self::e(implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) : '')) . '</small></td>';
        echo '<td data-sn-col="range">' . self::e($vlist ? (count($vlist) > 1 ? reset($vlist) . ' – ' . end($vlist) . ' (' . count($vlist) . ' versioni)' : reset($vlist)) : '—') . '</td><td>' . self::e($p['latest'] ?: '—') . '</td>';
        echo '<td data-sn-col="behind">' . ($behind ? '<strong>' . $behind . '</strong>' : '0') . '</td><td data-sn-col="issues">';
        if ($issues['vuln']) { echo self::badge($issues['vuln'] . ' vulnerabili', 'danger') . ' '; }
        if ($issues['blocked']) { echo self::badge($issues['blocked'] . ' bloccati', 'high') . ' '; }
        if ($issues['license']) { echo self::badge($issues['license'] . ' licenza', 'premium') . ' '; }
        if ($issues['blind']) { echo self::badge($issues['blind'] . ' versione ignota', 'muted'); }
        echo '</td></tr>';
    }

    private static function product_detail($p) {
        $vulnerable = 0; $ready_now = 0; $behind = 0;
        foreach ($p['sites'] as $s) {
            if (!empty($s['vuln']['count'])) { ++$vulnerable; }
            if ($s['status'] === 'update') { ++$ready_now; }
            if ($p['latest'] !== '' && Integra_Sentinel_Dependencies::valid_version((string) $s['ver']) && version_compare(ltrim($s['ver'], 'v'), ltrim($p['latest'], 'v'), '<')) { ++$behind; }
        }
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">' . ($p['type'] === 'theme' ? 'TEMA' : 'PLUGIN') . ' · ' . count($p['sites']) . ' SITI</p><h2>' . self::e($p['name']) . '</h2><p>Ultima versione nota: <strong>' . self::e($p['latest'] ?: 'sconosciuta') . '</strong> · <code>' . self::e($p['slug']) . '</code></p></div></section>';
        if ($vulnerable) {
            echo '<div class="sn-integrity-alert"><h3>' . (int) $vulnerable . ' siti con vulnerabilità note su questo prodotto</h3><p class="sn-hint" style="color:#731c28">Aggiorna per primi i siti segnati “Aggiornabile”; per gli altri la colonna Stato spiega cosa blocca (licenza, addon, PHP).</p></div>';
        }
        echo '<div class="sn-stats">';
        Integra_Sentinel_Master::sn_stat(count($p['sites']), 'Siti con il prodotto', $p['external'] ? 'Fuori repository' : 'Libreria WordPress.org', 'brand');
        Integra_Sentinel_Master::sn_stat($behind, 'Da aggiornare', 'rispetto all’ultima versione nota', $behind ? 'warn' : 'clear');
        Integra_Sentinel_Master::sn_stat($ready_now, 'Aggiornabili subito', 'nessun blocco noto', 'ok');
        Integra_Sentinel_Master::sn_stat($vulnerable, 'Con vulnerabilità note', 'sulla versione installata', $vulnerable ? 'danger' : 'clear');
        echo '</div>';
        $site_rows = $p['sites'];
        uasort($site_rows, function($a, $b) { return strcasecmp($a['label'], $b['label']); });
        echo '<section class="sn-section"><div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Sito</th><th>Versione</th><th>Stato</th><th>Dettaglio</th></tr></thead><tbody>';
        foreach ($site_rows as $site_id => $s) {
            $target = $s['new'] !== '' ? $s['new'] : $s['latest'];
            echo '<tr><td><a href="' . esc_url(Integra_Sentinel_Master::sn_admin_url($site_id) . '#sn-plugin-' . sanitize_html_class($p['slug'])) . '"><strong>' . self::e($s['label']) . '</strong></a>' . (!$s['active'] ? ' <em>inattivo</em>' : '') . '</td>';
            echo '<td>' . self::e($s['ver'] ?: '—') . ($target !== '' && $target !== $s['ver'] ? ' → <strong>' . self::e($target) . '</strong>' : '') . '</td>';
            echo '<td>' . Integra_Sentinel_Master::sn_badge($s['statusLabel'], Integra_Sentinel_Master::sn_status_tone($s['status']));
            if (!empty($s['vuln']['count'])) { echo ' ' . self::badge((int) $s['vuln']['count'] . ' CVE', $s['vuln']['tier'] ?: 'danger'); }
            if ($s['auto']) { echo ' ' . self::badge('Aggiornamento automatico', 'muted'); }
            echo '</td><td><small>' . self::e($s['reasons'][0] ?? $s['origin']) . '</small></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /* ---------- Comandi ---------- */

    private static function back($msg) {
        $page = isset($_POST['_sn_return']) ? sanitize_key(wp_unslash($_POST['_sn_return'])) : 'integra-sentinel';
        if (!isset(self::pages()[$page])) { $page = 'integra-sentinel'; }
        $args = array('sentinel_msg' => $msg);
        foreach (array('_sn_site' => 'site', '_sn_product' => 'product', '_sn_view' => 'view', '_sn_ym' => 'ym', '_sn_type' => 'type') as $field => $arg) {
            if (!empty($_POST[$field])) { $args[$arg] = sanitize_text_field(wp_unslash($_POST[$field])); }
        }
        $anchor = isset($_POST['_sn_anchor']) ? sanitize_html_class(wp_unslash($_POST['_sn_anchor'])) : '';
        wp_safe_redirect(self::url($page, $args) . ($anchor !== '' ? '#' . $anchor : ''));
        exit;
    }

    private static function post($key, $default = '') {
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
    }

    public static function handle() {
        if (!Integra_Sentinel_Settings::can_manage()) { wp_die('Operazione non autorizzata.', '', array('response' => 403)); }
        check_admin_referer('integra_sentinel_admin');
        $cmd = sanitize_key(self::post('cmd'));
        $admin_only = array('envato_token', 'webhooks', 'roles', 'pair_code', 'ai_settings', 'ai_test');
        if (in_array($cmd, $admin_only, true) && !current_user_can('manage_options')) { self::back('denied'); }
        $sites = Integra_Sentinel_Master::sites();
        $site_id = sanitize_text_field(self::post('site_id'));
        switch ($cmd) {
            case 'waiver_add':
            case 'mark_verified':
            case 'mark_tested':
            case 'mark_waiting':
                $kind = sanitize_key(self::post('kind'));
                $subject = sanitize_text_field(self::post('subject'));
                $fleet_wide = $cmd === 'waiver_add' && self::post('fleet');
                if (!$fleet_wide && !isset($sites[$site_id])) { self::back('invalid'); }
                $types = array('waiver_add' => 'waiver', 'mark_verified' => 'verified', 'mark_tested' => 'tested', 'mark_waiting' => 'waiting');
                if ($cmd === 'waiver_add') {
                    $until = (string) self::post('until');
                    $reason = sanitize_text_field(self::post('reason'));
                } elseif ($cmd === 'mark_verified') {
                    $days = in_array((int) self::post('days'), array(30, 60, 90, 180), true) ? (int) self::post('days') : 60;
                    $until = wp_date('Y-m-d', strtotime('+' . $days . ' days', current_time('timestamp')));
                    $reason = 'Aggiornato all’ultima versione';
                } else {
                    $until = '9999-12-31';
                    $reason = $cmd === 'mark_tested' ? 'Provato: funziona' : 'In attesa di aggiornamento';
                }
                $ok = Integra_Sentinel_Settings::waiver_add($site_id, $kind, $subject, $reason, $until, $fleet_wide, $types[$cmd], (string) self::post('sig'), (string) self::post('name'), sanitize_text_field(self::post('ver')));
                if ($ok) { Integra_Sentinel_Store::log($types[$cmd], $kind . ' · ' . $subject . ($reason !== '' ? ' · ' . $reason : ''), $fleet_wide ? '' : $site_id); }
                self::back($ok ? $types[$cmd] : 'invalid');
            case 'waiver_remove':
                Integra_Sentinel_Settings::waiver_remove(sanitize_key(self::post('id')));
                Integra_Sentinel_Store::log('waiver_remove', sanitize_key(self::post('id')));
                self::back('waiver_removed');
            case 'envato_token':
                $token = trim((string) self::post('envato_token'));
                if (self::post('remove')) {
                    Integra_Sentinel_Intel::envato_set_token('');
                    Integra_Sentinel_Store::log('envato_token', 'rimosso');
                    self::back('envato_removed');
                }
                if (!preg_match('/^[A-Za-z0-9]{20,64}$/', $token) || !Integra_Sentinel_Intel::envato_set_token($token)) { self::back('invalid'); }
                Integra_Sentinel_Store::log('envato_token', 'aggiornato');
                self::back(Integra_Sentinel_Intel::envato_refresh() ? 'envato' : 'envato_fail');
            case 'envato_refresh':
                self::back(Integra_Sentinel_Intel::envato_refresh() ? 'envato_ok' : 'envato_fail');
            case 'webhooks':
                $ok = Integra_Sentinel_Notify::save(array('slack' => self::post('slack'), 'generic' => self::post('generic'), 'telegram_token' => self::post('telegram_token'),
                    'telegram_chat' => self::post('telegram_chat'), 'events' => (array) self::post('events', array())));
                Integra_Sentinel_Store::log('webhooks', $ok ? 'salvati' : 'non validi');
                self::back($ok ? 'webhooks' : 'invalid');
            case 'webhook_test':
                $ok = Integra_Sentinel_Notify::send('test', 'Integra Sentinel · messaggio di prova', 'Se leggi questo messaggio, il canale è collegato correttamente.', Integra_Sentinel_Master::sn_admin_url(), true);
                self::back($ok ? 'webhook_test' : 'webhook_fail');
            case 'policies':
                $p = Integra_Sentinel_Settings::get('policies');
                $p['quarantine_days'] = max(0, min(30, absint(self::post('quarantine_days'))));
                $p['quarantine_scope'] = in_array(self::post('quarantine_scope'), array('hubs', 'all', 'off'), true) ? self::post('quarantine_scope') : 'hubs';
                $p['abandoned_years'] = max(1, min(6, absint(self::post('abandoned_years', 2))));
                $p['recheck_days'] = max(7, min(365, absint(self::post('recheck_days', 60))));
                $p['disk_min_mb'] = max(100, min(100000, absint(self::post('disk_min_mb', 500))));
                $p['backup_max_days'] = max(1, min(90, absint(self::post('backup_max_days', 7))));
                $p['probe_minutes'] = max(5, min(240, absint(self::post('probe_minutes', 15))));
                $p['rotation_grace_days'] = max(0, min(30, absint(self::post('rotation_grace_days', 7))));
                Integra_Sentinel_Settings::save('policies', $p);
                Integra_Sentinel_Settings::save('bad_versions', Integra_Sentinel_Settings::parse_bad_versions(self::post('bad_versions')));
                Integra_Sentinel_Settings::save('freezes', Integra_Sentinel_Settings::parse_freezes(self::post('freezes')));
                Integra_Sentinel_Store::log('policies', 'politiche di aggiornamento salvate');
                self::back('saved');
            case 'stack':
                Integra_Sentinel_Settings::save('stack', Integra_Sentinel_Settings::parse_stack(self::post('stack')));
                $est = Integra_Sentinel_Settings::get('estimates');
                foreach (array_keys(Integra_Sentinel_Settings::defaults()['estimates']) as $key) {
                    if (isset($_POST['est_' . $key])) { $est[$key] = max(0, min(2000, absint(self::post('est_' . $key)))); }
                }
                Integra_Sentinel_Settings::save('estimates', $est);
                Integra_Sentinel_Store::log('stack', 'stack standard e stime salvati');
                self::back('saved');
            case 'branding':
                $color = (string) self::post('color');
                Integra_Sentinel_Settings::save('branding', array(
                    'name' => substr(sanitize_text_field(self::post('name')), 0, 80) ?: 'Integra',
                    'url' => esc_url_raw((string) self::post('url'), array('https', 'http')),
                    'logo' => esc_url_raw((string) self::post('logo'), array('https')),
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#0c8997',
                    'footer' => substr(sanitize_text_field(self::post('footer')), 0, 240),
                    'from_name' => substr(sanitize_text_field(self::post('from_name')), 0, 80),
                ));
                Integra_Sentinel_Store::log('branding', 'marchio dei report salvato');
                self::back('saved');
            case 'roles':
                $roles = array_keys(wp_roles()->get_names());
                $view = array_values(array_intersect($roles, array_map('sanitize_key', (array) self::post('view', array()))));
                $manage = array_values(array_intersect($roles, array_map('sanitize_key', (array) self::post('manage', array()))));
                Integra_Sentinel_Settings::save('roles', array('view' => array_values(array_unique(array_merge($view, $manage))), 'manage' => $manage));
                Integra_Sentinel_Store::log('roles', 'visione: ' . implode(', ', $view) . ' · gestione: ' . implode(', ', $manage));
                self::back('saved');
            case 'scan':
                Integra_Sentinel_Settings::save('scan', array('batch' => max(2, min(12, absint(self::post('batch', 6))))));
                self::back('saved');
            case 'site_checklist':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $ok = Integra_Sentinel_Checklist::save($site_id, $_POST);
                if ($ok) { Integra_Sentinel_Store::log('site_checklist', 'checklist di manutenzione aggiornata', $site_id); }
                self::back($ok ? 'site_checklist' : 'busy');
            case 'pair_code':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $code = Integra_Sentinel_Master::pair_code($site_id);
                if ($code === '') { self::back('busy'); }
                set_transient('integra_sentinel_pair_once_' . get_current_user_id(), array('site' => $site_id, 'code' => $code), 30 * MINUTE_IN_SECONDS);
                Integra_Sentinel_Store::log('pair_code', 'codice di abbinamento generato', $site_id);
                self::back('pair');
            case 'probe_now':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                Integra_Sentinel_Probe::run($site_id, true);
                self::back('probe');
            case 'report_send':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $to = sanitize_email(self::post('to'));
                $ym = Integra_Sentinel_Reports::month_bounds(sanitize_text_field(self::post('ym')))['ym'];
                $ok = $to !== '' && Integra_Sentinel_Reports::send_monthly($sites[$site_id], $ym, $to);
                Integra_Sentinel_Store::log('report_send', ($ok ? 'inviato a ' : 'non inviato a ') . $to . ' · ' . $ym, $site_id);
                // Il resoconto approvato risulta inviato solo quando parte verso il contatto del cliente, non le prove a se stessi.
                $draft = Integra_Sentinel_Store::draft_get($site_id, $ym);
                $client_email = (string) ($sites[$site_id]['meta']['contact_email'] ?? '');
                if ($ok && $draft && in_array($draft['status'], array('approved', 'sent'), true) && $client_email !== '' && strtolower($to) === strtolower($client_email)) {
                    Integra_Sentinel_Store::draft_save($site_id, $ym, array('status' => 'sent', 'sent_to' => $to));
                }
                self::back($ok ? 'report_sent' : 'report_fail');
            case 'drafts_all':
                $n = Integra_Sentinel_Reports::enqueue_drafts(sanitize_text_field(self::post('ym')), 'manual');
                if ($n) { spawn_cron(); }
                self::back($n ? 'drafts_queued' : 'drafts_none');
            case 'drafts_cancel':
                delete_option(Integra_Sentinel_Reports::QUEUE);
                wp_clear_scheduled_hook(Integra_Sentinel_Reports::HOOK_QUEUE);
                Integra_Sentinel_Store::log('drafts_queue', 'interrotta');
                self::back('drafts_cancelled');
            case 'component_notes':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $rows = array();
                foreach ((array) self::post('cnotes', array()) as $key => $row) {
                    $rows[sanitize_text_field((string) $key)] = array('text' => (string) ($row['text'] ?? ''), 'fleet' => !empty($row['fleet']));
                }
                $ok = Integra_Sentinel_Reports::save_component_notes($site_id, $rows);
                if ($ok) { Integra_Sentinel_Store::log('component_notes', 'spiegazioni sui componenti salvate', $site_id); }
                self::back($ok ? 'component_notes' : 'busy');
            case 'event_notes':
                // Si scrivono solo le righe cambiate rispetto a quanto mostrato nella pagina.
                $orig = (array) self::post('orig', array());
                $saved = 0; $failed = 0;
                foreach ((array) self::post('notes', array()) as $eid => $note) {
                    $eid = absint($eid);
                    if (!$eid || (string) $note === (string) ($orig[$eid] ?? '')) { continue; }
                    $event = Integra_Sentinel_Store::event_get($eid);
                    if ($event && Integra_Sentinel_Store::event_note($eid, (string) $note)) { ++$saved; Integra_Sentinel_Store::log('event_note', $event['message'], $event['site_id']); }
                    else { ++$failed; }
                }
                self::back($failed ? 'event_notes_partial' : ($saved ? 'event_notes' : 'event_notes_none'));
            case 'ai_settings':
                $ok = Integra_Sentinel_AI::save(array('provider' => self::post('provider'), 'model' => self::post('model'), 'base_url' => self::post('base_url'),
                    'key' => self::post('ai_key'), 'register' => self::post('register'), 'style' => self::post('style'), 'auto_drafts' => self::post('auto_drafts'), 'fallback' => self::post('fallback')));
                Integra_Sentinel_Store::log('ai_settings', $ok ? 'salvate' : 'non valide');
                self::back($ok ? 'ai_saved' : 'invalid');
            case 'ai_test':
                self::back(Integra_Sentinel_AI::test() ? 'ai_ok' : 'ai_fail');
            case 'report_ai':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $ym = Integra_Sentinel_Reports::month_bounds(sanitize_text_field(self::post('ym')))['ym'];
                $result = Integra_Sentinel_Reports::ai_draft($sites[$site_id], $ym, (string) self::post('notes'));
                if (!$result['ok']) { set_transient('integra_sentinel_ai_error_' . get_current_user_id(), $result['error'], 10 * MINUTE_IN_SECONDS); }
                Integra_Sentinel_Store::log('report_ai', ($result['ok'] ? 'bozza scritta con ' : 'bozza non riuscita con ') . $result['model'] . ' · ' . $ym, $site_id);
                self::back($result['ok'] ? 'draft_ai' : 'draft_ai_fail');
            case 'report_draft':
                if (!isset($sites[$site_id])) { self::back('invalid'); }
                $ym = Integra_Sentinel_Reports::month_bounds(sanitize_text_field(self::post('ym')))['ym'];
                $approve = self::post('approve') !== '';
                $body = (string) self::post('body');
                if ($approve && trim($body) === '') { self::back('invalid'); }
                $fields = array('body' => $body);
                // Ogni modifica dopo l'approvazione torna "da rileggere", salvo riapprovazione esplicita.
                $old = Integra_Sentinel_Store::draft_get($site_id, $ym);
                if ($approve) { $fields['status'] = 'approved'; }
                elseif (!$old || trim((string) $old['body']) !== trim(sanitize_textarea_field($body))) { $fields['status'] = 'draft'; }
                $ok = Integra_Sentinel_Store::draft_save($site_id, $ym, $fields);
                if ($ok) { Integra_Sentinel_Store::log('report_draft', ($approve ? 'approvato' : 'salvato') . ' · ' . $ym, $site_id); }
                self::back($ok ? ($approve ? 'draft_approved' : 'draft_saved') : 'invalid');
            case 'daily_now':
                Integra_Sentinel_Jobs::maybe_daily(true);
                self::back('daily');
            case 'intel_now':
                Integra_Sentinel_Intel::tick(25.0);
                self::back('intel');
        }
        self::back('invalid');
    }

    /** Documenti stampabili: report mensile e audit di presa in carico. */
    public static function document() {
        if (!Integra_Sentinel_Settings::can_view()) { wp_die('Non autorizzato.', '', array('response' => 403)); }
        check_admin_referer('integra_sentinel_document');
        $sites = Integra_Sentinel_Master::sites();
        $site_id = isset($_GET['site']) ? sanitize_text_field(wp_unslash($_GET['site'])) : '';
        if (!isset($sites[$site_id])) { wp_die('Sito non trovato.', '', array('response' => 404)); }
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'monthly';
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo $type === 'onboarding' ? Integra_Sentinel_Reports::onboarding_html($sites[$site_id])
            : Integra_Sentinel_Reports::monthly_html($sites[$site_id], isset($_GET['ym']) ? sanitize_text_field(wp_unslash($_GET['ym'])) : '', !empty($_GET['preview']));
        exit;
    }

    public static function document_url($type, $site_id, $ym = '', $preview = false) {
        $args = array('action' => 'integra_sentinel_document', 'type' => $type, 'site' => $site_id);
        if ($ym !== '') { $args['ym'] = $ym; }
        if ($preview) { $args['preview'] = 1; }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'integra_sentinel_document');
    }

    /** Esportazione CSV dei colli di bottiglia (una riga per sito), apribile con Excel. */
    public static function csv() {
        if (!Integra_Sentinel_Settings::can_view()) { wp_die('Non autorizzato.', '', array('response' => 403)); }
        check_admin_referer('integra_sentinel_csv');
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $sites = Integra_Sentinel_Master::sites();
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sentinel-colli-di-bottiglia-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('Sito', 'URL', 'Causa', 'Componente', 'Gravità', 'Problema', 'Dettaglio', 'Cosa fare', 'Minuti stimati', 'Sicurezza', 'Segnato'), ';');
        foreach ($fleet['kinds'] as $kind => $rows) {
            foreach ($rows as $b) {
                fputcsv($out, array(self::csv_cell($b['site_label']), $sites[$b['site_id']]['url'] ?? '', self::csv_cell(Integra_Sentinel_Fleet::kind_label($kind)), self::csv_cell($b['subjectName']),
                    Integra_Sentinel_Fleet::severity_label($b['severity']), self::csv_cell($b['title']), self::csv_cell($b['detail']), self::csv_cell($b['action']), (int) $b['minutes'],
                    !empty($b['security']) ? 'sì' : 'no', self::csv_cell(Integra_Sentinel_Settings::mark_types()[$b['mark']['type'] ?? ''] ?? '')), ';');
            }
        }
        fclose($out);
        exit;
    }

    /** Evita l'esecuzione di formule quando il CSV viene aperto in un foglio di calcolo. */
    private static function csv_cell($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
