<?php
/**
 * Integra Sentinel — pagine Licenze, Interventi, Report clienti, Impostazioni e i pannelli aggiuntivi
 * della scheda sito (scheda cliente, ambiente, temi, uptime, richieste al cliente).
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Admin_Pages {

    private static function e($value) { return esc_html((string) $value); }

    private static function badge($text, $tone = 'muted') {
        return '<span class="sn-badge sn-' . esc_attr($tone) . '">' . self::e($text) . '</span>';
    }

    /** Utenti che possono vedere Sentinel (amministratori e ruoli abilitati). */
    public static function users() {
        $roles = Integra_Sentinel_Settings::get('roles');
        $role_in = array_values(array_unique(array_merge(array('administrator'), (array) ($roles['view'] ?? array()), (array) ($roles['manage'] ?? array()))));
        return get_users(array('role__in' => $role_in, 'fields' => array('ID', 'display_name'), 'number' => 200, 'orderby' => 'display_name'));
    }

    public static function user_select($name, $selected) {
        $html = '<select name="' . esc_attr($name) . '"><option value="0">—</option>';
        foreach (self::users() as $u) { $html .= '<option value="' . (int) $u->ID . '"' . selected((int) $selected, (int) $u->ID, false) . '>' . self::e($u->display_name) . '</option>'; }
        return $html . '</select>';
    }

    private static function site_select($name, $selected, $sites, $empty = '—') {
        $html = '<select name="' . esc_attr($name) . '"><option value="">' . self::e($empty) . '</option>';
        foreach ($sites as $id => $site) { $html .= '<option value="' . esc_attr($id) . '"' . selected($selected, $id, false) . '>' . self::e($site['label']) . '</option>'; }
        return $html . '</select>';
    }

    /* ---------- Licenze ---------- */

    public static function licenses() {
        $sites = Integra_Sentinel_Master::sites();
        $all = Integra_Sentinel_Licenses::all();
        $usage = Integra_Sentinel_Licenses::usage($sites);
        $costs = Integra_Sentinel_Licenses::annual_cost();
        $types = Integra_Sentinel_Licenses::types();
        $manage = Integra_Sentinel_Settings::can_manage();
        Integra_Sentinel_Admin::open('integra-sentinel-licenses', self::badge(count($all) . ' licenze', 'brand'));
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">REGISTRO LICENZE</p><h2>Chi possiede ogni licenza premium</h2><p>Serve a capire, per ogni licenza mancante su un sito, se basta attivare quella dell’agenzia o se deve intervenire il cliente. Qui non si salvano le chiavi di licenza.</p></div>'
            . ($manage ? '<a class="button button-primary" href="#sn-license-form">+ Registra licenza</a>' : '') . '</section>';
        $expiring = Integra_Sentinel_Licenses::expiring(30);
        $expired = 0;
        foreach ($all as $entry) { if (Integra_Sentinel_Licenses::status($entry) === 'expired') { ++$expired; } }
        echo '<div class="sn-stats">';
        Integra_Sentinel_Master::sn_stat(count($all), 'Licenze registrate', count(Integra_Sentinel_Intel::envato_items()) . ' acquisti Envato letti', 'brand');
        Integra_Sentinel_Master::sn_stat(number_format_i18n($costs['agenzia'], 0) . ' €', 'Costo annuo agenzia', 'rinnovi a carico dell’agenzia', 'neutral');
        Integra_Sentinel_Master::sn_stat(number_format_i18n($costs['cliente'], 0) . ' €', 'Costo annuo clienti', 'rinnovi a carico dei clienti', 'neutral');
        Integra_Sentinel_Master::sn_stat(count($expiring), 'In scadenza entro 30 giorni', $expired . ' già scadute', count($expiring) || $expired ? 'warn' : 'clear');
        echo '</div>';

        echo '<section class="sn-section"><div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Prodotto</th><th>Tipo</th><th>Siti</th><th>Scadenza</th><th>Costo / anno</th><th>Paga</th><th></th></tr></thead><tbody>';
        if (!$all) { echo '<tr><td colspan="7" class="sn-muted">Nessuna licenza registrata. Inizia da quelle segnalate nei colli di bottiglia o dall’elenco “Premium senza licenza registrata” qui sotto.</td></tr>'; }
        foreach ($all as $id => $entry) {
            $status = Integra_Sentinel_Licenses::status($entry);
            $left = Integra_Sentinel_Licenses::days_left($entry);
            $used = (array) ($usage[$id]['sites'] ?? array());
            $exp = $status === 'perpetual' ? self::badge('Perpetua', 'ok') : ($status === 'expired' ? self::badge('Scaduta il ' . wp_date('d/m/Y', strtotime($entry['expires'] . ' 12:00:00')), 'danger')
                : ($status === 'soon' ? self::badge('Tra ' . $left . ' giorni', 'warn') : self::e(wp_date('d/m/Y', strtotime($entry['expires'] . ' 12:00:00')))));
            echo '<tr><td><strong>' . self::e($entry['product']) . '</strong><br><small><code>' . self::e(implode(', ', $entry['slugs'])) . '</code></small>'
                . (!empty($entry['vendor_url']) ? ' · <a href="' . esc_url($entry['vendor_url']) . '" target="_blank" rel="noopener noreferrer">fornitore ↗</a>' : '') . '</td>';
            echo '<td>' . self::e($types[$entry['type']] ?? $entry['type']) . '</td><td>' . count($used) . ($entry['type'] === 'agency_seats' ? ' / ' . (int) $entry['seats'] : '')
                . (!empty($usage[$id]['over']) ? ' ' . self::badge('Oltre i siti acquistati', 'danger') : '') . ($used ? '<br><small>' . self::e(implode(', ', array_slice($used, 0, 4)) . (count($used) > 4 ? '…' : '')) . '</small>' : '') . '</td>';
            echo '<td>' . $exp . '</td><td>' . ($entry['cost'] ? self::e(number_format_i18n((float) $entry['cost'], 2)) . ' €' : '—') . '</td><td>' . self::e($entry['payer'] === 'cliente' ? 'Cliente' : 'Agenzia') . '</td><td class="sn-row-actions">';
            if ($manage) {
                echo '<a class="button button-small" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-licenses', array('edit' => $id)) . '#sn-license-form') . '">Modifica</a>';
                Integra_Sentinel_Admin::form_start('license_delete', 'integra-sentinel-licenses', array('id' => $id));
                echo '<button class="button button-small" type="submit" onclick="return confirm(\'Eliminare questa licenza dal registro?\')">Elimina</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';

        // Premium presenti nel parco ma non coperti dal registro.
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $covered = array();
        foreach ($all as $entry) { foreach ((array) $entry['slugs'] as $slug) { $covered[$slug] = true; } }
        $missing = array();
        foreach (Integra_Sentinel_Fleet::products($fleet) as $p) {
            if (!$p['premium'] || isset($covered[$p['slug']])) { continue; }
            $missing[] = $p;
        }
        echo '<section class="sn-panel"><div class="sn-section-head"><div><h3>Premium senza licenza registrata</h3><p>Prodotti commerciali presenti nel parco per cui non sai ancora chi possiede la licenza.</p></div><span class="sn-counter">' . count($missing) . '</span></div>';
        if (!$missing) { echo '<p class="sn-muted">Tutti i premium del parco sono coperti dal registro.</p>'; }
        else {
            echo '<ul class="sn-origin-list">';
            foreach (array_slice($missing, 0, 60) as $p) {
                echo '<li><div><strong>' . self::e($p['name']) . '</strong> ' . self::badge($p['type'] === 'theme' ? 'Tema' : 'Plugin', 'muted') . '<small><code>' . self::e($p['slug']) . '</code> · ' . count($p['sites']) . ' siti</small></div>';
                if ($manage) { echo '<div class="sn-origin-ver"><a class="button button-small" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-licenses', array('new' => 1, 'product' => $p['name'], 'slugs' => $p['slug'])) . '#sn-license-form') . '">Registra</a></div>'; }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';

        if ($manage) { self::license_form($sites); }
        self::envato_panel($sites);
        Integra_Sentinel_Admin::close();
    }

    private static function license_form($sites) {
        $edit = isset($_GET['edit']) ? Integra_Sentinel_Licenses::get(sanitize_key(wp_unslash($_GET['edit']))) : null;
        $entry = $edit ?: array('id' => '', 'product' => isset($_GET['product']) ? sanitize_text_field(wp_unslash($_GET['product'])) : '',
            'slugs' => isset($_GET['slugs']) ? array(sanitize_title(wp_unslash($_GET['slugs']))) : array(), 'type' => 'agency_unlimited', 'seats' => 0, 'expires' => '',
            'cost' => 0, 'payer' => 'agenzia', 'sites' => array(), 'vendor_url' => '', 'notes' => '');
        echo '<section class="sn-panel sn-form-panel" id="sn-license-form"><h3>' . ($edit ? 'Modifica licenza' : 'Registra una licenza') . '</h3>';
        Integra_Sentinel_Admin::form_start('license_save', 'integra-sentinel-licenses', array('id' => $entry['id']));
        echo '<div class="sn-form-grid">';
        echo '<label>Prodotto<input type="text" name="product" required maxlength="120" value="' . esc_attr($entry['product']) . '" placeholder="Elementor Pro"></label>';
        echo '<label>Slug coperti (separati da virgola)<input type="text" name="slugs" required value="' . esc_attr(implode(', ', (array) $entry['slugs'])) . '" placeholder="elementor-pro"></label>';
        echo '<label>Tipo<select name="type">';
        foreach (Integra_Sentinel_Licenses::types() as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($entry['type'], $key, false) . '>' . self::e($label) . '</option>'; }
        echo '</select></label>';
        echo '<label>Numero di siti (solo “numero di siti”)<input type="number" min="1" name="seats" value="' . (int) ($entry['seats'] ?: 1) . '"></label>';
        echo '<label>Scadenza<input type="date" name="expires" value="' . esc_attr($entry['expires']) . '"></label>';
        echo '<label>Costo annuo (€)<input type="number" min="0" step="0.01" name="cost" value="' . esc_attr((string) $entry['cost']) . '"></label>';
        echo '<label>Chi paga il rinnovo<select name="payer"><option value="agenzia"' . selected($entry['payer'], 'agenzia', false) . '>Agenzia</option><option value="cliente"' . selected($entry['payer'], 'cliente', false) . '>Cliente</option></select></label>';
        echo '<label>Pagina del fornitore<input type="url" name="vendor_url" value="' . esc_attr($entry['vendor_url']) . '" placeholder="https://"></label>';
        echo '<label class="sn-span-2">Siti coperti (licenze del cliente o con siti assegnati; vuoto = tutti)<select name="sites[]" multiple size="5">';
        foreach ($sites as $id => $site) { echo '<option value="' . esc_attr($id) . '"' . (in_array($id, (array) $entry['sites'], true) ? ' selected' : '') . '>' . self::e($site['label']) . '</option>'; }
        echo '</select></label>';
        echo '<label class="sn-span-2">Note<textarea name="notes" rows="2">' . esc_textarea($entry['notes']) . '</textarea></label>';
        echo '</div>';
        submit_button($edit ? 'Salva modifiche' : 'Registra licenza', 'primary', 'submit', false);
        echo '</form></section>';
    }

    private static function envato_panel($sites) {
        $cfg = Integra_Sentinel_Intel::envato_config();
        $items = Integra_Sentinel_Intel::envato_items();
        echo '<section class="sn-panel" id="sn-envato"><div class="sn-section-head"><div><h3>Acquisti Envato (ThemeForest e CodeCanyon)</h3><p>Con un token personale Envato il Master legge ogni giorno gli acquisti dell’agenzia e ne ricava le ultime versioni: i premium Envato non risultano più “punti ciechi”. Il token resta sul Master, cifrato.</p></div>'
            . self::badge(!empty($cfg['token']) ? 'Collegato' : 'Non collegato', !empty($cfg['token']) ? 'ok' : 'muted') . '</div>';
        if (!empty($cfg['token'])) {
            echo '<p class="sn-hint">Ultima lettura: ' . (!empty($cfg['at']) ? self::e(wp_date('d/m/Y H:i', (int) $cfg['at'])) : 'mai') . ' · ' . count($items) . ' prodotti' . (!empty($cfg['error']) ? ' · <strong>' . self::e($cfg['error']) . '</strong>' : '') . '</p>';
        }
        if (current_user_can('manage_options')) {
            Integra_Sentinel_Admin::form_start('envato_token', 'integra-sentinel-licenses', array('_sn_anchor' => 'sn-envato'));
            echo '<p><label>Token personale Envato (permesso “Visualizza e cerca i tuoi acquisti”)<br><input type="password" class="regular-text" name="envato_token" autocomplete="new-password" placeholder="' . (!empty($cfg['token']) ? 'Token già salvato: inseriscine uno nuovo per sostituirlo' : 'Incolla il token creato su build.envato.com') . '"></label></p>';
            echo '<p><label><input type="checkbox" name="remove" value="1"> Rimuovi il token salvato</label></p>';
            submit_button('Salva token', 'secondary', 'submit', false);
            echo '</form>';
        }
        if (!empty($cfg['token']) && Integra_Sentinel_Settings::can_manage()) {
            Integra_Sentinel_Admin::form_start('envato_refresh', 'integra-sentinel-licenses', array('_sn_anchor' => 'sn-envato'));
            echo '<p><button type="submit" class="button">Leggi gli acquisti adesso</button></p></form>';
        }
        if ($items) {
            $matched = Integra_Sentinel_Intel::envato_catalog($sites, 'plugin') + Integra_Sentinel_Intel::envato_catalog($sites, 'theme');
            $by_id = array();
            foreach ($matched as $slug => $m) { $by_id[(int) $m['envato_id']][] = $slug; }
            echo '<details class="sn-nested-details"><summary>' . count($items) . ' acquisti letti</summary><table class="sn-simple-table"><thead><tr><th>Prodotto</th><th>Versione</th><th>Supporto fino al</th><th>Nel parco</th></tr></thead><tbody>';
            foreach ($items as $item) {
                echo '<tr><td>' . self::e($item['name']) . ' ' . self::badge($item['kind'] === 'theme' ? 'Tema' : 'Plugin', 'muted') . '</td><td>' . self::e($item['version'] ?: '—') . '</td><td>'
                    . self::e($item['supported_until'] ? wp_date('d/m/Y', strtotime($item['supported_until'] . ' 12:00:00')) : '—') . '</td><td>' . (isset($by_id[(int) $item['id']]) ? '<code>' . self::e(implode(', ', $by_id[(int) $item['id']])) . '</code>' : '—') . '</td></tr>';
            }
            echo '</tbody></table><p class="sn-hint">Le licenze Envato sono perpetue: la scadenza del supporto non impedisce di scaricare gli aggiornamenti dal proprio account.</p></details>';
        }
        echo '</section>';
    }

    /* ---------- Interventi ---------- */

    public static function tasks() {
        $sites = Integra_Sentinel_Master::sites();
        $statuses = Integra_Sentinel_Store::task_statuses();
        $filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'active';
        $mine = !empty($_GET['mine']);
        $tasks = Integra_Sentinel_Store::tasks(array('status' => $filter === 'all' ? '' : $filter, 'assignee' => $mine ? get_current_user_id() : 0, 'limit' => 500));
        $counts = Integra_Sentinel_Store::task_counts();
        $users = array();
        foreach (self::users() as $u) { $users[(int) $u->ID] = $u->display_name; }
        $manage = Integra_Sentinel_Settings::can_manage();
        Integra_Sentinel_Admin::open('integra-sentinel-tasks', $manage ? '<a class="button button-primary" href="#sn-task-form">+ Nuovo intervento</a>' : '');
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">INTERVENTI</p><h2>Chi fa cosa, e quando</h2><p>Crea interventi dai colli di bottiglia o a mano. Quando il problema collegato sparisce dai dati del sito, l’intervento si chiude da solo.</p></div></section>';
        echo '<div class="sn-chips sn-chips-links" role="navigation" aria-label="Filtra interventi">';
        $active_total = 0;
        foreach ($counts as $status => $n) { if ($status !== 'done') { $active_total += $n; } }
        $links = array('active' => 'Aperti (' . $active_total . ')') + array_map(function($label) { return $label; }, $statuses) + array('all' => 'Tutti');
        foreach ($links as $key => $label) {
            $label = isset($statuses[$key]) ? $label . ' (' . (int) ($counts[$key] ?? 0) . ')' : $label;
            echo '<a class="sn-chip' . ($filter === $key ? ' is-selected' : '') . '" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-tasks', array('status' => $key) + ($mine ? array('mine' => 1) : array()))) . '">' . self::e($label) . '</a>';
        }
        echo '<a class="sn-chip' . ($mine ? ' is-selected' : '') . '" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-tasks', array('status' => $filter) + ($mine ? array() : array('mine' => 1)))) . '">Solo i miei</a></div>';
        echo '<section class="sn-section"><div class="sn-table-wrap"><table class="sn-simple-table sn-tasks"><thead><tr><th>Intervento</th><th>Sito</th><th>Assegnato</th><th>Scadenza</th><th>Stato</th><th>Tempo</th><th></th></tr></thead><tbody>';
        if (!$tasks) { echo '<tr><td colspan="7" class="sn-muted">Nessun intervento con questo filtro.</td></tr>'; }
        $today = wp_date('Y-m-d');
        foreach ($tasks as $t) {
            $late = $t['due_date'] && $t['due_date'] < $today && $t['status'] !== 'done';
            echo '<tr><td><strong>' . self::e($t['title']) . '</strong>' . ($t['kind'] !== '' ? '<br><small>' . self::e(Integra_Sentinel_Fleet::kind_label($t['kind'])) . ($t['subject'] !== '' ? ' · <code>' . self::e($t['subject']) . '</code>' : '') . '</small>' : '')
                . ($t['notes'] !== '' ? '<details class="sn-task-notes"><summary>Note</summary><p>' . nl2br(self::e($t['notes'])) . '</p></details>' : '') . '</td>';
            echo '<td>' . (isset($sites[$t['site_id']]) ? '<a href="' . esc_url(Integra_Sentinel_Master::sn_admin_url($t['site_id'])) . '">' . self::e($sites[$t['site_id']]['label']) . '</a>' : '—') . '</td>';
            echo '<td>' . self::e($users[(int) $t['assignee']] ?? '—') . '</td><td>' . ($t['due_date'] ? ($late ? self::badge(wp_date('d/m/Y', strtotime($t['due_date'] . ' 12:00:00')), 'danger') : self::e(wp_date('d/m/Y', strtotime($t['due_date'] . ' 12:00:00')))) : '—') . '</td><td>';
            if ($manage) {
                Integra_Sentinel_Admin::form_start('task_status', 'integra-sentinel-tasks', array('id' => $t['id']), 'sn-inline-form');
                echo '<select name="status" aria-label="Stato dell’intervento" onchange="this.form.submit()">';
                foreach ($statuses as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($t['status'], $key, false) . '>' . self::e($label) . '</option>'; }
                echo '</select><noscript><button class="button button-small" type="submit">OK</button></noscript></form>';
            } else { echo self::e($statuses[$t['status']] ?? $t['status']); }
            echo '</td><td>' . ((int) $t['minutes'] ? self::e(Integra_Sentinel_Admin::minutes_text((int) $t['minutes'])) : '—') . '</td><td class="sn-row-actions">';
            if ($manage) {
                echo '<a class="button button-small" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-tasks', array('task' => $t['id'], 'status' => $filter)) . '#sn-task-form') . '">Modifica</a>';
                Integra_Sentinel_Admin::form_start('task_delete', 'integra-sentinel-tasks', array('id' => $t['id']));
                echo '<button class="button button-small" type="submit" onclick="return confirm(\'Eliminare questo intervento?\')">Elimina</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
        if ($manage) {
            $task = isset($_GET['task']) ? Integra_Sentinel_Store::task_get(absint($_GET['task'])) : null;
            $t = $task ?: array('id' => 0, 'site_id' => isset($_GET['site']) ? sanitize_text_field(wp_unslash($_GET['site'])) : '', 'title' => '', 'kind' => '', 'subject' => '', 'status' => 'open', 'assignee' => get_current_user_id(),
                'due_date' => '', 'minutes' => 0, 'notes' => '');
            echo '<section class="sn-panel sn-form-panel" id="sn-task-form"><h3>' . ($task ? 'Modifica intervento' : 'Nuovo intervento') . '</h3>';
            Integra_Sentinel_Admin::form_start('task_save', 'integra-sentinel-tasks', array('id' => $t['id'], 'kind' => $t['kind'], 'subject' => $t['subject']));
            echo '<div class="sn-form-grid"><label class="sn-span-2">Titolo<input type="text" name="title" required maxlength="200" value="' . esc_attr($t['title']) . '"></label>';
            echo '<label>Sito' . self::site_select('site_id', $t['site_id'], $sites, 'Nessun sito / più siti') . '</label>';
            echo '<label>Assegnato a' . self::user_select('assignee', $t['assignee']) . '</label>';
            echo '<label>Stato<select name="status">';
            foreach ($statuses as $key => $label) { echo '<option value="' . esc_attr($key) . '"' . selected($t['status'], $key, false) . '>' . self::e($label) . '</option>'; }
            echo '</select></label><label>Scadenza<input type="date" name="due_date" value="' . esc_attr((string) $t['due_date']) . '"></label>';
            echo '<label>Minuti impiegati<input type="number" min="0" name="minutes" value="' . (int) $t['minutes'] . '"></label>';
            echo '<label class="sn-span-2">Note<textarea name="notes" rows="3">' . esc_textarea($t['notes']) . '</textarea></label></div>';
            submit_button($task ? 'Salva intervento' : 'Crea intervento', 'primary', 'submit', false);
            echo '</form></section>';
        }
        Integra_Sentinel_Admin::close();
    }

    /* ---------- Storico ---------- */

    public static function event_types() {
        return array('update' => 'Aggiornamento', 'core' => 'WordPress', 'install' => 'Installato', 'remove' => 'Rimosso', 'admin' => 'Amministratori', 'note' => 'Nota');
    }

    /** Testo dell'evento senza ripetere l'etichetta del tipo ("Installato …", "Rimosso …"). */
    public static function event_text($e) {
        return trim((string) preg_replace('/^(Installato|Rimosso)\s+/u', '', (string) $e['message']));
    }

    /** Tutti i cambiamenti rilevati sui siti, mese per mese, con il motivo scritto dall'agenzia. */
    public static function history() {
        $sites = Integra_Sentinel_Master::sites();
        $ym = isset($_GET['ym']) ? sanitize_text_field(wp_unslash($_GET['ym'])) : wp_date('Y-m');
        $period = Integra_Sentinel_Reports::month_bounds($ym);
        $site_id = isset($_GET['site']) ? sanitize_text_field(wp_unslash($_GET['site'])) : '';
        if (!isset($sites[$site_id])) { $site_id = ''; }
        $filters = array('updates' => array('Aggiornamenti', array('update', 'core')), 'changes' => array('Aggiornamenti, installazioni e rimozioni', array('update', 'core', 'install', 'remove')), 'all' => array('Tutto, anche amministratori', array()));
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'changes';
        if (!isset($filters[$type])) { $type = 'changes'; }
        $events = Integra_Sentinel_Store::events_between($period['start'], $period['end'], $site_id, $filters[$type][1], 2000);
        $manage = Integra_Sentinel_Settings::can_manage();
        $labels = self::event_types();
        Integra_Sentinel_Admin::open('integra-sentinel-history', self::badge(count($events) . ' cambiamenti', 'brand'));
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">STORICO</p><h2>Cosa è cambiato sui siti</h2><p>Ogni aggiornamento, installazione o rimozione viene registrato confrontando gli inventari inviati dai Client, di solito pochi minuti dopo l’operazione. Scrivi il motivo accanto agli aggiornamenti: finirà nel resoconto per il cliente.</p></div></section>';

        $this_month = wp_date('Y-m');
        $last_month = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
        $link = function($args) use ($site_id, $type) { return Integra_Sentinel_Admin::url('integra-sentinel-history', array_filter($args + array('site' => $site_id, 'type' => $type))); };
        echo '<form method="get" class="sn-month-form"><input type="hidden" name="page" value="integra-sentinel-history">';
        echo '<label>Mese <input type="month" name="ym" value="' . esc_attr($period['ym']) . '"></label>';
        echo '<label>Sito <select name="site"><option value="">Tutti i siti</option>';
        foreach ($sites as $id => $site) { echo '<option value="' . esc_attr($id) . '"' . selected($site_id, $id, false) . '>' . self::e($site['label']) . '</option>'; }
        echo '</select></label><label>Mostra <select name="type">';
        foreach ($filters as $key => $f) { echo '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . self::e($f[0]) . '</option>'; }
        echo '</select></label> <button class="button" type="submit">Mostra</button>';
        echo ' <a class="button-link" href="' . esc_url($link(array('ym' => $this_month))) . '">Mese in corso</a> · <a class="button-link" href="' . esc_url($link(array('ym' => $last_month))) . '">Mese scorso</a></form>';

        // Siti muti: se il Client non invia, il Master non puo' registrare nulla.
        $silent = array();
        foreach ($sites as $id => $site) {
            if ($site_id !== '' && $id !== $site_id) { continue; }
            if (empty($site['last_seen']) || time() - (int) $site['last_seen'] > 36 * HOUR_IN_SECONDS) {
                $silent[] = '<a href="' . esc_url(Integra_Sentinel_Master::sn_admin_url($id)) . '">' . self::e($site['label']) . '</a> (' . (!empty($site['last_seen']) ? 'ultimo inventario ' . self::e(wp_date('d/m/Y H:i', (int) $site['last_seen'])) : 'nessun inventario ricevuto') . ')';
            }
        }
        if ($silent) { echo '<div class="notice notice-warning inline"><p><strong>Questi siti non inviano l’inventario da più di 36 ore, quindi i loro aggiornamenti non vengono registrati:</strong> ' . implode(', ', $silent) . '. Controlla il Client in Impostazioni → Integra Sentinel sul sito.</p></div>'; }

        echo '<section class="sn-section">';
        if (!$events) {
            echo '<div class="sn-panel sn-empty"><p>Nessun cambiamento registrato a ' . self::e($period['label']) . ($site_id !== '' ? ' per ' . self::e($sites[$site_id]['label']) : '') . '.' . ($period['ym'] !== $this_month ? ' <a href="' . esc_url($link(array('ym' => $this_month))) . '">Guarda il mese in corso →</a>' : '') . '</p></div>';
        } else {
            if ($manage) {
                // Un solo modulo per tutta la tabella: "Salva" registra tutti i motivi della pagina, non solo una riga.
                Integra_Sentinel_Admin::form_start('event_notes', 'integra-sentinel-history', array('_sn_ym' => $period['ym'], '_sn_site' => $site_id, '_sn_type' => $type), 'sn-history-form');
                echo '<p class="sn-history-save"><button type="submit" class="button button-primary">Salva tutti i motivi</button></p>';
            }
            echo '<div class="sn-table-wrap"><table class="sn-simple-table sn-history"><thead><tr><th>Data</th>' . ($site_id === '' ? '<th>Sito</th>' : '') . '<th>Cambiamento</th><th>Motivo <small>(per il resoconto al cliente)</small></th></tr></thead><tbody>';
            foreach ($events as $e) {
                $tone = array('update' => 'brand', 'core' => 'brand', 'install' => 'ok', 'remove' => 'muted', 'admin' => 'warn')[$e['type']] ?? 'muted';
                echo '<tr id="sn-ev-' . (int) $e['id'] . '"><td>' . self::e(wp_date('d/m/Y H:i', (int) $e['at'])) . '</td>';
                if ($site_id === '') { echo '<td><a href="' . esc_url($link(array('ym' => $period['ym'], 'site' => $e['site_id']))) . '">' . self::e($sites[$e['site_id']]['label'] ?? 'Sito rimosso') . '</a></td>'; }
                echo '<td>' . self::badge($labels[$e['type']] ?? $e['type'], $tone) . ' ' . self::e(self::event_text($e)) . (($e['flags'] ?? '') === 'auto' ? ' ' . self::badge('automatico', 'muted') : '') . '</td><td>';
                if ($manage && in_array($e['type'], array('update', 'core', 'install', 'remove'), true)) {
                    $eid = (int) $e['id'];
                    echo '<input type="text" class="sn-note-input" name="notes[' . $eid . ']" maxlength="1000" value="' . esc_attr((string) $e['note']) . '" placeholder="Es.: corregge un problema al checkout segnalato dal cliente" aria-label="Motivo">'
                        . '<input type="hidden" name="orig[' . $eid . ']" value="' . esc_attr((string) $e['note']) . '">';
                } else { echo self::e((string) $e['note']); }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($manage) { echo '<p class="sn-history-save"><button type="submit" class="button button-primary">Salva tutti i motivi</button></p></form>'; }
        }
        echo '<p class="sn-hint">“automatico” = applicato da WordPress con gli aggiornamenti automatici (rilevato dal Client 1.8 o successivo). Lo storico viene conservato per 2 anni. Il motivo non è obbligatorio: se manca, il resoconto dice solo che il componente è stato aggiornato (o che chiudeva falle di sicurezza note, quando risulta dai database delle vulnerabilità).</p></section>';
        Integra_Sentinel_Admin::close();
    }

    /* ---------- Report clienti ---------- */

    public static function reports() {
        $sites = Integra_Sentinel_Master::sites();
        $period = Integra_Sentinel_Reports::month_bounds(isset($_GET['ym']) ? sanitize_text_field(wp_unslash($_GET['ym'])) : '');
        $site_id = isset($_GET['site']) ? sanitize_text_field(wp_unslash($_GET['site'])) : '';
        if ($site_id !== '' && isset($sites[$site_id])) { self::report_editor($sites[$site_id], $period); return; }
        $drafts = Integra_Sentinel_Store::drafts_for_month($period['ym']);
        $statuses = Integra_Sentinel_Store::draft_statuses();
        $manage = Integra_Sentinel_Settings::can_manage();
        Integra_Sentinel_Admin::open('integra-sentinel-reports');
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">REPORT CLIENTI</p><h2>Cosa abbiamo fatto, spiegato ai clienti</h2><p>Per ogni sito prepari il resoconto del mese: l’IA scrive una bozza comprensibile partendo dai dati raccolti, tu la rileggi, la approvi e la invii insieme al report con i numeri.</p></div></section>';
        if (!Integra_Sentinel_AI::configured()) {
            echo '<div class="notice notice-info inline"><p>Per far scrivere le bozze all’IA aggiungi una chiave API in <a href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-settings') . '#sn-ai') . '">Impostazioni → Intelligenza artificiale</a> (Google Gemini ha un piano gratuito). Nel frattempo puoi scrivere i resoconti a mano.</p></div>';
        }
        $this_month = wp_date('Y-m');
        $last_month = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
        echo '<form method="get" class="sn-month-form"><input type="hidden" name="page" value="integra-sentinel-reports"><label>Mese <input type="month" name="ym" value="' . esc_attr($period['ym']) . '"></label> <button class="button" type="submit">Mostra</button>'
            . ' <a class="button-link" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-reports', array('ym' => $last_month))) . '">Mese scorso</a> · <a class="button-link" href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-reports', array('ym' => $this_month))) . '">Mese in corso (finora)</a></form>';
        $queue = Integra_Sentinel_Reports::queue();
        if ($queue) {
            // Se il cron si e' interrotto, la coda riparte da qui.
            if (!wp_next_scheduled(Integra_Sentinel_Reports::HOOK_QUEUE)) { wp_schedule_single_event(time() + 5, Integra_Sentinel_Reports::HOOK_QUEUE); }
            $qp = Integra_Sentinel_Reports::month_bounds($queue['ym']);
            echo '<div class="notice notice-info inline"><p><strong>Bozze di ' . self::e($qp['label']) . ' in preparazione:</strong> ' . (int) ($queue['total'] - count($queue['sites'])) . ' di ' . (int) $queue['total'] . ' completate' . ($queue['failed'] ? ', ' . count($queue['failed']) . ' non riuscite' : '') . '. Ricarica la pagina per vedere l’avanzamento.';
            if ($manage) {
                echo '</p>';
                Integra_Sentinel_Admin::form_start('drafts_cancel', 'integra-sentinel-reports', array('_sn_ym' => $period['ym']));
                echo '<p><button class="button button-small" type="submit">Interrompi</button></p></form>';
            } else { echo '</p>'; }
            echo '</div>';
        } elseif ($manage && Integra_Sentinel_AI::configured()) {
            Integra_Sentinel_Admin::form_start('drafts_all', 'integra-sentinel-reports', array('ym' => $period['ym'], '_sn_ym' => $period['ym']), 'sn-drafts-all');
            echo '<p><button class="button button-primary" type="submit">Prepara tutte le bozze di ' . self::e($period['label']) . '</button> <span class="sn-hint">Solo per i siti che non hanno ancora un resoconto: quelli già scritti non vengono toccati.</span></p></form>';
        }
        $counts = Integra_Sentinel_Store::update_counts($period['start'], $period['end']);
        echo '<section class="sn-section"><div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Sito</th><th>Aggiornamenti registrati</th><th>Resoconto di ' . self::e($period['label']) . '</th><th>Documenti</th></tr></thead><tbody>';
        if (!$sites) { echo '<tr><td colspan="4" class="sn-muted">Nessun sito registrato.</td></tr>'; }
        foreach ($sites as $id => $site) {
            $meta = (array) ($site['meta'] ?? array());
            $d = $drafts[$id] ?? null;
            $editor = Integra_Sentinel_Admin::url('integra-sentinel-reports', array('site' => $id, 'ym' => $period['ym']));
            echo '<tr><td><strong>' . self::e($site['label']) . '</strong><br><small>' . self::e(trim(($meta['contact_name'] ?? '') . ' ' . ($meta['contact_email'] ?? '')) ?: 'Nessun contatto: impostalo nella scheda cliente') . '</small></td>';
            $n = (int) ($counts[$id] ?? 0);
            echo '<td><a href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-history', array('ym' => $period['ym'], 'site' => $id))) . '">' . ($n ? $n . ' ' . ($n === 1 ? 'aggiornamento' : 'aggiornamenti') : 'nessuno') . '</a></td><td class="sn-row-actions">';
            if ($d && trim((string) $d['body']) !== '') {
                $tone = array('draft' => 'warn', 'approved' => 'brand', 'sent' => 'ok')[$d['status']] ?? 'muted';
                echo self::badge($statuses[$d['status']] ?? $d['status'], $tone) . ' <small>' . self::e($d['status'] === 'sent' ? 'il ' . wp_date('d/m/Y', (int) $d['sent_at']) : 'aggiornato il ' . wp_date('d/m/Y', (int) $d['updated_at'])) . '</small> ';
                echo '<a class="button button-small" href="' . esc_url($editor) . '">Apri</a>';
            } else {
                echo '<a class="button button-small button-primary" href="' . esc_url($editor) . '">Prepara il resoconto</a>';
            }
            echo '</td><td class="sn-row-actions"><a class="button button-small" target="_blank" rel="noopener" href="' . esc_url(Integra_Sentinel_Admin::document_url('monthly', $id, $period['ym'])) . '">Report del mese ↗</a> <a class="button button-small" target="_blank" rel="noopener" href="' . esc_url(Integra_Sentinel_Admin::document_url('onboarding', $id)) . '">Audit di presa in carico ↗</a></td></tr>';
        }
        echo '</tbody></table></div><p class="sn-hint">Il contatto del cliente si imposta nella scheda cliente di ogni sito. L’invio usa wp_mail del Master: serve un SMTP configurato.</p></section>';
        Integra_Sentinel_Admin::close();
    }

    /**
     * Spiegazioni fisse sui componenti: prima quelli che non si aggiornano o hanno problemi, poi gli altri (chiusi).
     * Le note valgono per tutti i mesi; con "tutti i siti" valgono anche per gli altri siti con lo stesso componente.
     */
    private static function component_notes_panel($site, $period, $manage) {
        $id = (string) $site['id'];
        $r = (array) ($site['report'] ?? array());
        $a = Integra_Sentinel_Analysis::site($site);
        $nodes = array();
        if ($a) { foreach ($a['graph']['nodes'] as $n) { $nodes[$n['id']] = $n; } }
        $themes = array();
        if ($a) { foreach ($a['themes']['themes'] as $t) { $themes[$t['slug']] = $t; } }
        $rows = array();
        foreach ((array) ($r['plugins'] ?? array()) as $p) {
            $n = $nodes[$p['slug']] ?? null;
            $flags = array();
            if ($n && !empty($n['vuln']['count'])) { $flags[] = array('Vulnerabilità note', 'danger'); }
            if ($n && in_array($n['status'], array('blocked', 'held', 'bottleneck', 'license', 'missing'), true)) { $flags[] = array('Non aggiornabile', 'high'); }
            elseif (!empty($p['update']['version'])) { $flags[] = array('Aggiornamento disponibile', 'brand'); }
            if ($n && in_array($n['status'], array('abandoned', 'blind', 'manual'), true)) { $flags[] = array($n['statusLabel'], 'muted'); }
            if ($n && !in_array($n['origin'], array('wporg', 'internal'), true)) { $flags[] = array($n['envato'] ? 'Envato' : 'Fuori da WordPress.org', 'premium'); }
            $rows[] = array('key' => 'plugin:' . $p['slug'], 'name' => $p['name'], 'version' => $p['version'], 'flags' => $flags, 'hint' => $n ? (string) ($n['reasons'][0] ?? '') : '',
                'note' => Integra_Sentinel_Reports::component_note($id, 'plugin', $p['slug']));
        }
        foreach ((array) ($r['themes'] ?? array()) as $t) {
            $info = $themes[$t['slug']] ?? null;
            $flags = array();
            if ($info && !empty($info['vuln']['count'])) { $flags[] = array('Vulnerabilità note', 'danger'); }
            if (!empty($t['update'])) { $flags[] = array($info && $info['status'] !== 'update' ? 'Non aggiornabile' : 'Aggiornamento disponibile', $info && $info['status'] !== 'update' ? 'high' : 'brand'); }
            if ($info && !empty($info['premium'])) { $flags[] = array('Fuori da WordPress.org', 'premium'); }
            $rows[] = array('key' => 'theme:' . $t['slug'], 'name' => $t['name'] . ' (tema)', 'version' => $t['version'], 'flags' => $flags, 'hint' => $info ? (string) ($info['reasons'][0] ?? '') : '',
                'note' => Integra_Sentinel_Reports::component_note($id, 'theme', $t['slug']));
        }
        $main = array_filter($rows, function($row) { return $row['flags'] || $row['note']['text'] !== ''; });
        $other = array_filter($rows, function($row) { return !$row['flags'] && $row['note']['text'] === ''; });
        echo '<section class="sn-panel" id="sn-component-notes"><h3>1 · Spiegazioni fisse sui componenti <small class="sn-muted">(facoltative, valgono per tutti i mesi)</small></h3>';
        echo '<p class="sn-hint">Per i plugin o i temi che non si possono aggiornare o hanno una situazione particolare, scrivi una spiegazione per il cliente: comparirà in ogni report di questo sito, nella sezione “Situazione di alcuni componenti”, e verrà usata dall’IA. Es.: “Plugin realizzato su misura da un’altra agenzia: non riceve aggiornamenti, ma non risultano vulnerabilità note.”</p>';
        if (!$rows) { echo '<p class="sn-muted">Nessun inventario ricevuto.</p></section>'; return; }
        $render = function($row) use ($manage) {
            $field = esc_attr($row['key']);
            echo '<div class="sn-cnote"><div class="sn-cnote-head"><strong>' . self::e($row['name']) . '</strong> <small>' . self::e($row['version']) . '</small> ';
            foreach ($row['flags'] as $f) { echo self::badge($f[0], $f[1]) . ' '; }
            echo '</div>';
            if ($row['hint'] !== '') { echo '<p class="sn-hint">Rilevato da Sentinel: ' . self::e($row['hint']) . '</p>'; }
            if ($manage) {
                echo '<textarea name="cnotes[' . $field . '][text]" rows="2" class="large-text" placeholder="Spiegazione per il cliente (facoltativa)">' . esc_textarea($row['note']['text']) . '</textarea>';
                echo '<label class="sn-inline-check"><input type="checkbox" name="cnotes[' . $field . '][fleet]" value="1"' . checked($row['note']['fleet'], true, false) . '> Vale per tutti i siti che hanno questo componente</label>';
            } elseif ($row['note']['text'] !== '') { echo '<p>' . self::e($row['note']['text']) . '</p>'; }
            echo '</div>';
        };
        if ($manage) { Integra_Sentinel_Admin::form_start('component_notes', 'integra-sentinel-reports', array('site_id' => $id, '_sn_site' => $id, '_sn_ym' => $period['ym'], '_sn_anchor' => 'sn-component-notes')); }
        if (!$main) { echo '<p class="sn-muted">Nessun componente con problemi: tutti aggiornati e senza vulnerabilità note.</p>'; }
        foreach ($main as $row) { $render($row); }
        if ($other) {
            echo '<details class="sn-nested-details"><summary>Altri componenti (' . count($other) . ')</summary>';
            foreach ($other as $row) { $render($row); }
            echo '</details>';
        }
        if ($manage) { echo '<p><button type="submit" class="button">Salva le spiegazioni</button></p></form>'; }
        echo '</section>';
    }

    /** Resoconto di un sito: note per l'IA, bozza da rileggere e approvare, anteprima e invio. */
    private static function report_editor($site, $period) {
        $id = (string) $site['id'];
        $meta = (array) ($site['meta'] ?? array());
        $manage = Integra_Sentinel_Settings::can_manage();
        $draft = Integra_Sentinel_Store::draft_get($id, $period['ym']);
        $body = (string) ($draft['body'] ?? '');
        $status = (string) ($draft['status'] ?? '');
        $back = Integra_Sentinel_Admin::url('integra-sentinel-reports', array('ym' => $period['ym']));
        Integra_Sentinel_Admin::open('integra-sentinel-reports', '<a class="button" href="' . esc_url($back) . '">← Tutti i siti</a>');
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">RESOCONTO PER IL CLIENTE · ' . self::e(strtoupper($period['label'])) . '</p><h2>' . self::e($site['label']) . '</h2><p>L’IA usa solo i dati raccolti da Sentinel e le tue note: non deve inventare motivazioni. Rileggi sempre la bozza prima di approvarla.</p></div>';
        if ($status !== '') {
            $tone = array('draft' => 'warn', 'approved' => 'brand', 'sent' => 'ok')[$status] ?? 'muted';
            echo '<div>' . self::badge(Integra_Sentinel_Store::draft_statuses()[$status] ?? $status, $tone) . '</div>';
        }
        echo '</section>';
        $error = get_transient('integra_sentinel_ai_error_' . get_current_user_id());
        if ($error) { delete_transient('integra_sentinel_ai_error_' . get_current_user_id()); echo '<div class="notice notice-error inline"><p>' . self::e($error) . '</p></div>'; }
        if (empty($site['report'])) { echo '<div class="notice notice-warning inline"><p>Il sito non ha ancora inviato un inventario: il resoconto avrebbe pochi dati.</p></div>'; }

        $ai = Integra_Sentinel_AI::configured();
        echo '<div class="sn-report-steps">';
        $month_updates = (int) (Integra_Sentinel_Store::update_counts($period['start'], $period['end'])[$id] ?? 0);
        $history_url = Integra_Sentinel_Admin::url('integra-sentinel-history', array('ym' => $period['ym'], 'site' => $id));
        echo '<p class="sn-hint">' . ($month_updates ? 'A ' . self::e($period['label']) . ' sono registrati <a href="' . esc_url($history_url) . '">' . $month_updates . ' aggiornamenti</a>: nello Storico puoi scrivere il motivo di ciascuno.' : 'Nessun aggiornamento registrato a ' . self::e($period['label']) . '. <a href="' . esc_url($history_url) . '">Apri lo Storico</a> per controllare, oppure scegli un altro mese.') . '</p>';
        self::component_notes_panel($site, $period, $manage);
        echo '<section class="sn-panel"><h3>2 · Note per questo mese <small class="sn-muted">(facoltative)</small></h3><p class="sn-hint">Scrivi qui ciò che i dati non sanno: perché hai lasciato un aggiornamento in sospeso, cosa hai verificato, accordi presi con il cliente. L’IA le userà come fonte affidabile.</p>';
        if ($manage) {
            Integra_Sentinel_Admin::form_start('report_ai', 'integra-sentinel-reports', array('site_id' => $id, 'ym' => $period['ym'], '_sn_site' => $id, '_sn_ym' => $period['ym']), 'sn-ai-form');
            echo '<textarea name="notes" rows="5" class="large-text" placeholder="Es.: Elementor Pro resta alla versione attuale perché la licenza è scaduta: aspettiamo la decisione del cliente sul rinnovo. Dopo l’aggiornamento di WooCommerce abbiamo fatto un ordine di prova.">' . esc_textarea((string) ($draft['notes'] ?? '')) . '</textarea>';
            if ($ai) {
                $confirm = $body !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode('La bozza attuale verrà sostituita da una nuova versione scritta dall’IA. Continuare?')) . ')"' : '';
                echo '<p><button type="submit" class="button button-primary" data-sn-busy="L’IA sta scrivendo… può servire fino a un minuto"' . $confirm . '>' . ($body !== '' ? 'Riscrivi la bozza con l’IA' : 'Scrivi la bozza con l’IA') . '</button></p>';
            } else {
                echo '<p class="sn-hint">IA non configurata: ' . (current_user_can('manage_options') ? '<a href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-settings') . '#sn-ai') . '">aggiungi una chiave API</a>' : 'chiedi a un amministratore di aggiungere una chiave API') . ', oppure scrivi il resoconto a mano qui sotto.</p>';
            }
            echo '</form>';
        }
        echo '<details class="sn-nested-details"><summary>Quali dati vengono inviati all’IA</summary><p class="sn-hint">Solo questi fatti: nomi e versioni dei componenti, date, conteggi, stato tecnico. Mai credenziali, email, nomi degli amministratori o percorsi dei file.</p><pre class="sn-facts">'
            . esc_html(wp_json_encode(Integra_Sentinel_Reports::facts($site, $period['ym'], (string) ($draft['notes'] ?? '')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        echo '</section>';

        echo '<section class="sn-panel"><h3>3 · Rileggi e correggi</h3><p class="sn-hint">Titoli con <code>## </code>, elenchi con <code>- </code>. Il cliente vede il testo solo dopo l’approvazione; ogni modifica successiva va riapprovata.'
            . (!empty($draft['model']) ? ' Ultima bozza scritta con <code>' . self::e($draft['model']) . '</code>.' : '') . '</p>';
        if ($manage) {
            Integra_Sentinel_Admin::form_start('report_draft', 'integra-sentinel-reports', array('site_id' => $id, 'ym' => $period['ym'], '_sn_site' => $id, '_sn_ym' => $period['ym']));
            echo '<textarea name="body" rows="22" class="large-text sn-draft-body" placeholder="## Il mese in breve&#10;…">' . esc_textarea($body) . '</textarea>';
            echo '<p class="sn-actions"><button type="submit" class="button">Salva</button> <button type="submit" class="button button-primary" name="approve" value="1">' . ($status === 'approved' || $status === 'sent' ? 'Salva e mantieni approvato' : 'Approva il resoconto') . '</button></p></form>';
        } else { echo $body !== '' ? '<div class="sn-draft-preview">' . Integra_Sentinel_AI::to_html($body) . '</div>' : '<p class="sn-muted">Nessun resoconto.</p>'; }
        echo '</section>';

        echo '<section class="sn-panel"><h3>4 · Anteprima e invio</h3><p class="sn-hint">Il cliente riceve un’email con il resoconto in alto e, sotto, i numeri del mese: aggiornamenti, disponibilità, sicurezza e prossimi passi.</p><div class="sn-actions">';
        echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(Integra_Sentinel_Admin::document_url('monthly', $id, $period['ym'], true)) . '">Anteprima ↗</a>';
        if ($manage) {
            $me = wp_get_current_user();
            Integra_Sentinel_Admin::form_start('report_send', 'integra-sentinel-reports', array('site_id' => $id, 'ym' => $period['ym'], 'to' => (string) $me->user_email, '_sn_site' => $id, '_sn_ym' => $period['ym']));
            echo '<button class="button" type="submit">Invia una prova a me</button></form>';
            if (empty($meta['contact_email'])) { echo '<span class="sn-hint">Per inviarlo al cliente imposta l’email nella <a href="' . esc_url(Integra_Sentinel_Master::sn_admin_url($id) . '#sn-meta') . '">scheda cliente</a>.</span>'; }
            elseif (!in_array($status, array('approved', 'sent'), true)) { echo '<span class="sn-hint">Approva il resoconto per poterlo inviare a ' . self::e($meta['contact_email']) . '.</span>'; }
            else {
                Integra_Sentinel_Admin::form_start('report_send', 'integra-sentinel-reports', array('site_id' => $id, 'ym' => $period['ym'], 'to' => $meta['contact_email'], '_sn_site' => $id, '_sn_ym' => $period['ym']));
                echo '<button class="button button-primary" type="submit" onclick="return confirm(' . esc_attr(wp_json_encode('Inviare il report di ' . $period['label'] . ' a ' . $meta['contact_email'] . '?')) . ')">' . ($status === 'sent' ? 'Invia di nuovo al cliente' : 'Invia al cliente') . '</button></form>';
                if ($status === 'sent') { echo '<span class="sn-hint">Inviato a ' . self::e($draft['sent_to']) . ' il ' . self::e(wp_date('d/m/Y H:i', (int) $draft['sent_at'])) . '.</span>'; }
            }
        }
        echo '</div></section></div>';
        Integra_Sentinel_Admin::close();
    }

    /* ---------- Impostazioni ---------- */

    public static function settings() {
        $manage = Integra_Sentinel_Settings::can_manage();
        $admin = current_user_can('manage_options');
        Integra_Sentinel_Admin::open('integra-sentinel-settings');
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">IMPOSTAZIONI</p><h2>Notifiche, fonti, politiche e accessi</h2><p>Tutto resta sul Master: i siti dei clienti non ricevono né salvano queste impostazioni.</p></div></section>';
        echo '<nav class="sn-subnav" aria-label="Sezioni delle impostazioni"><a href="#sn-mail">Email</a><a href="#sn-webhooks">Slack · Telegram · Make</a><a href="#sn-sources">Fonti dati</a><a href="#sn-deps-settings">Catalogo premium</a><a href="#sn-policies">Politiche</a><a href="#sn-stack">Stack e stime</a><a href="#sn-ai">Intelligenza artificiale</a><a href="#sn-branding">Marchio report</a><a href="#sn-access">Accessi</a><a href="#sn-audit">Registro attività</a></nav>';
        if (!$manage) { echo '<div class="notice notice-info inline"><p>Hai accesso in sola visione: le impostazioni sono modificabili da chi gestisce Sentinel.</p></div>'; Integra_Sentinel_Admin::close(); return; }

        echo '<section class="sn-panel" id="sn-mail">';
        Integra_Sentinel_Master::email_settings_form('integra-sentinel-settings');
        echo '</section>';

        $hooks = Integra_Sentinel_Settings::get('webhooks');
        echo '<section class="sn-panel" id="sn-webhooks"><h3>Notifiche su Slack, Telegram e Make</h3><p class="sn-hint">Gli stessi allarmi delle email arrivano anche qui. Webhook compatibili Slack: Slack, Mattermost, Google Chat, Discord (URL che termina con /slack). Il webhook generico invia JSON a Make, Zapier o n8n. URL e token sono cifrati; lascia un campo vuoto per mantenerlo, scrivi “-” per cancellarlo.</p>';
        if ($admin) {
            Integra_Sentinel_Admin::form_start('webhooks', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-webhooks'));
            echo '<div class="sn-form-grid">';
            echo '<label>Webhook compatibile Slack<input type="password" autocomplete="new-password" name="slack" placeholder="' . (!empty($hooks['slack']) ? 'Configurato' : 'https://hooks.slack.com/services/…') . '"></label>';
            echo '<label>Webhook generico (JSON)<input type="password" autocomplete="new-password" name="generic" placeholder="' . (!empty($hooks['generic']) ? 'Configurato' : 'https://hook.eu1.make.com/…') . '"></label>';
            echo '<label>Token del bot Telegram<input type="password" autocomplete="new-password" name="telegram_token" placeholder="' . (!empty($hooks['telegram_token']) ? 'Configurato' : '123456789:AA…') . '"></label>';
            echo '<label>Chat Telegram (ID o @canale)<input type="text" name="telegram_chat" value="' . esc_attr($hooks['telegram_chat'] ?? '') . '"></label>';
            echo '<fieldset class="sn-span-2"><legend>Eventi da inviare</legend>';
            foreach (Integra_Sentinel_Notify::events() as $key => $label) { echo '<label class="sn-inline-check"><input type="checkbox" name="events[]" value="' . esc_attr($key) . '"' . checked(in_array($key, (array) $hooks['events'], true), true, false) . '> ' . self::e($label) . '</label>'; }
            echo '</fieldset></div>';
            submit_button('Salva canali', 'secondary', 'submit', false);
            echo '</form>';
        } else { echo '<p class="sn-muted">Solo gli amministratori possono modificare i canali.</p>'; }
        if (Integra_Sentinel_Notify::configured()) {
            Integra_Sentinel_Admin::form_start('webhook_test', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-webhooks'));
            echo '<p><button class="button" type="submit">Invia un messaggio di prova</button></p></form>';
        }
        echo '</section>';

        $scan = Integra_Sentinel_Settings::get('scan');
        echo '<section class="sn-panel" id="sn-sources"><h3>Fonti dati</h3><p class="sn-hint">WPVulnerability è sempre attivo. Il Master consulta inoltre WordPress.org (plugin chiusi, abbandonati, data di rilascio, checksum ufficiali) e, se collegato, l’account Envato. Nessuna di queste richieste parte dai siti dei clienti.</p>';
        Integra_Sentinel_Admin::form_start('scan', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-sources'));
        echo '<p><label>Componenti controllati in parallelo a ogni giro <input type="number" min="2" max="12" name="batch" value="' . (int) ($scan['batch'] ?? 6) . '"></label> ';
        submit_button('Salva', 'secondary small', 'submit', false);
        echo '</p></form>';
        Integra_Sentinel_Admin::form_start('intel_now', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-sources'));
        $wporg = Integra_Sentinel_Intel::wporg_info();
        $sums = Integra_Sentinel_Intel::checksums();
        echo '<p class="sn-hint">In archivio: ' . count($wporg) . ' schede WordPress.org · ' . count($sums['plugins']) . ' checksum di plugin · ' . count($sums['core']) . ' versioni del core. <button class="button button-small" type="submit">Aggiorna adesso</button> · <a href="' . esc_url(Integra_Sentinel_Admin::url('integra-sentinel-licenses') . '#sn-envato') . '">Collegamento Envato →</a></p></form>';
        Integra_Sentinel_Master::wpscan_settings(Integra_Sentinel_Master::sites(), 'integra-sentinel-settings');
        echo '</section>';

        Integra_Sentinel_Master::dependency_settings_form('integra-sentinel-settings');

        $p = Integra_Sentinel_Settings::get('policies');
        $bad = array(); foreach ((array) Integra_Sentinel_Settings::get('bad_versions') as $r) { $bad[] = implode(' | ', array_filter(array($r[0] ?? '', $r[1] ?? '', $r[2] ?? ''), 'strlen')); }
        $freezes = array(); foreach ((array) Integra_Sentinel_Settings::get('freezes') as $f) { $freezes[] = ($f[0] ?? '') . ' > ' . ($f[1] ?? '') . ' | ' . ($f[2] ?? '') . (!empty($f[3]) ? ' | ' . $f[3] : ''); }
        echo '<section class="sn-panel" id="sn-policies"><h3>Politiche di aggiornamento</h3><p class="sn-hint">Indicazioni per il lavoro dell’agenzia: Sentinel le mostra nel piano e nei colli di bottiglia, ma non aggiorna nulla da solo.</p>';
        Integra_Sentinel_Admin::form_start('policies', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-policies'));
        echo '<div class="sn-form-grid">';
        echo '<label>Quarantena delle nuove versioni x.y.0 (giorni)<input type="number" min="0" max="30" name="quarantine_days" value="' . (int) $p['quarantine_days'] . '"></label>';
        echo '<label>Quarantena applicata a<select name="quarantine_scope"><option value="hubs"' . selected($p['quarantine_scope'], 'hubs', false) . '>Plugin principali (WooCommerce, Elementor…)</option><option value="all"' . selected($p['quarantine_scope'], 'all', false) . '>Tutti i plugin WordPress.org</option><option value="off"' . selected($p['quarantine_scope'], 'off', false) . '>Disattivata</option></select></label>';
        echo '<label>Plugin abbandonato dopo (anni senza aggiornamenti)<input type="number" min="1" max="6" name="abandoned_years" value="' . (int) $p['abandoned_years'] . '"></label>';
        echo '<label>Backup considerato vecchio dopo (giorni)<input type="number" min="1" max="90" name="backup_max_days" value="' . (int) $p['backup_max_days'] . '"></label>';
        echo '<label>Spazio libero minimo (MB)<input type="number" min="100" name="disk_min_mb" value="' . (int) $p['disk_min_mb'] . '"></label>';
        echo '<label>Controllo raggiungibilità ogni (minuti)<input type="number" min="5" max="240" name="probe_minutes" value="' . (int) $p['probe_minutes'] . '"></label>';
        echo '<label>Tolleranza dopo la rigenerazione chiave (giorni)<input type="number" min="0" max="30" name="rotation_grace_days" value="' . (int) $p['rotation_grace_days'] . '"></label>';
        echo '<label class="sn-inline-check"><input type="checkbox" name="license_reminders" value="1"' . checked(!empty($p['license_reminders']), true, false) . '> Promemoria licenze a 30 e 7 giorni</label>';
        echo '<label class="sn-span-2">Versioni da evitare (una per riga: <code>slug | versione o intervallo | nota</code>)<textarea name="bad_versions" rows="4" class="code" spellcheck="false" placeholder="elementor | 3.25.0 | rompe l’header&#10;woocommerce | >=9.4.0,<9.4.2 | bug al checkout">' . esc_textarea(implode("\n", $bad)) . '</textarea></label>';
        echo '<label class="sn-span-2">Periodi di blocco (una per riga: <code>AAAA-MM-GG > AAAA-MM-GG | etichetta | tag</code>; senza tag valgono per tutti i siti)<textarea name="freezes" rows="3" class="code" spellcheck="false" placeholder="2026-11-20 > 2026-12-02 | Black Friday | ecommerce">' . esc_textarea(implode("\n", $freezes)) . '</textarea></label>';
        echo '</div>';
        submit_button('Salva politiche', 'secondary', 'submit', false);
        echo '</form></section>';

        $est = Integra_Sentinel_Settings::get('estimates');
        $est_labels = array('update' => 'Aggiornamento semplice', 'cluster' => 'Nucleo con addon (con prova)', 'manual' => 'Premium manuale', 'license' => 'Sistemare una licenza', 'theme' => 'Aggiornamento tema',
            'env' => 'PHP / piattaforma', 'server' => 'Problema del server', 'postupdate' => 'Lavoro post-aggiornamento', 'replace' => 'Sostituire un plugin', 'modified' => 'File modificati', 'missing' => 'Dipendenza mancante', 'cleanup' => 'Pulizia');
        echo '<section class="sn-panel" id="sn-stack"><h3>Stack standard e stime dei tempi</h3>';
        Integra_Sentinel_Admin::form_start('stack', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-stack'));
        echo '<div class="sn-two"><div><label>Plugin approvati dall’agenzia (uno slug per riga; vuoto = controllo disattivato)<textarea name="stack" rows="10" class="large-text code" spellcheck="false" placeholder="wordpress-seo&#10;wp-rocket&#10;updraftplus">' . esc_textarea(implode("\n", (array) Integra_Sentinel_Settings::get('stack'))) . '</textarea></label></div><div><p><strong>Minuti stimati per intervento</strong></p><div class="sn-form-grid sn-form-grid-compact">';
        foreach ($est_labels as $key => $label) { echo '<label>' . self::e($label) . '<input type="number" min="0" max="2000" name="est_' . esc_attr($key) . '" value="' . (int) ($est[$key] ?? 0) . '"></label>'; }
        echo '</div></div></div>';
        submit_button('Salva stack e stime', 'secondary', 'submit', false);
        echo '</form></section>';

        $ai = Integra_Sentinel_AI::config();
        $ai_status = Integra_Sentinel_AI::status();
        echo '<section class="sn-panel" id="sn-ai"><h3>Intelligenza artificiale per i resoconti ai clienti</h3><p class="sn-hint">L’IA trasforma i dati del mese in un testo comprensibile per il cliente (Report clienti → Prepara il resoconto). Riceve solo nomi e versioni dei componenti, date e conteggi: mai credenziali, email, nomi degli amministratori o percorsi dei file. La chiave è cifrata.</p>';
        if ($admin) {
            Integra_Sentinel_Admin::form_start('ai_settings', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-ai'));
            echo '<div class="sn-form-grid"><label>Fornitore<select name="provider">';
            foreach (Integra_Sentinel_AI::providers() as $key => $prov) { echo '<option value="' . esc_attr($key) . '"' . selected($ai['provider'], $key, false) . '>' . self::e($prov['label']) . '</option>'; }
            echo '</select></label>';
            echo '<label>Chiave API<input type="password" autocomplete="new-password" name="ai_key" placeholder="' . ($ai['key'] !== '' ? 'Configurata: lascia vuoto per mantenerla, “-” per cancellarla' : 'Incolla la chiave') . '"></label>';
            $stored = (string) (Integra_Sentinel_Settings::get('ai')['model'] ?? '');
            echo '<label>Modello <small>(vuoto = predefinito del fornitore)</small><input type="text" name="model" value="' . esc_attr($stored) . '" placeholder="' . esc_attr(Integra_Sentinel_AI::providers()[$ai['provider']]['model'] ?: 'nome del modello') . '"></label>';
            $fallback_auto = Integra_Sentinel_AI::fallback_models(array('provider' => $ai['provider'], 'model' => $ai['model']));
            echo '<label>Modello di riserva <small>(se il principale è sovraccarico; vuoto = scelto in automatico)</small><input type="text" name="fallback" value="' . esc_attr((string) (Integra_Sentinel_Settings::get('ai')['fallback'] ?? '')) . '" placeholder="' . esc_attr($fallback_auto ? implode(', ', $fallback_auto) : 'Premi “Verifica il collegamento” per l’elenco dei modelli') . '"></label>';
            echo '<label>Indirizzo base API <small>(solo per “Compatibile OpenAI”)</small><input type="url" name="base_url" value="' . esc_attr($ai['base_url']) . '" placeholder="https://api.openai.com/v1"></label>';
            echo '<label>Come rivolgersi al cliente<select name="register"><option value="voi"' . selected($ai['register'], 'voi', false) . '>Voi (“il vostro sito”)</option><option value="lei"' . selected($ai['register'], 'lei', false) . '>Lei (“il Suo sito”)</option><option value="tu"' . selected($ai['register'], 'tu', false) . '>Tu (“il tuo sito”)</option></select></label>';
            echo '<label class="sn-inline-check sn-span-2"><input type="checkbox" name="auto_drafts" value="1"' . checked(!empty(Integra_Sentinel_Settings::get('ai')['auto_drafts']), true, false) . '> Il primo del mese prepara da sola le bozze del mese precedente per tutti i siti e avvisa (email e canali di notifica) quando sono pronte da rileggere. Nessuna bozza viene inviata senza la tua approvazione.</label>';
            echo '<label class="sn-span-2">Indicazioni di stile <small>(facoltative)</small><textarea name="style" rows="2" placeholder="Es.: frasi brevi; chiudi sempre ricordando che siamo disponibili per chiarimenti.">' . esc_textarea($ai['style']) . '</textarea></label></div>';
            echo '<ul class="sn-hint">';
            foreach (Integra_Sentinel_AI::providers() as $prov) { echo '<li><strong>' . self::e($prov['label']) . '</strong>: ' . self::e($prov['hint']) . '</li>'; }
            echo '</ul>';
            submit_button('Salva', 'secondary', 'submit', false);
            echo '</form>';
            if ($ai['key'] !== '') {
                Integra_Sentinel_Admin::form_start('ai_test', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-ai'));
                echo '<p><button class="button" type="submit">Verifica il collegamento</button></p></form>';
            }
            if (!empty($ai_status['at'])) {
                echo '<p class="sn-hint">Ultima verifica ' . self::e(wp_date('d/m/Y H:i', (int) $ai_status['at'])) . ' con <code>' . self::e($ai_status['model']) . '</code>: '
                    . (!empty($ai_status['ok']) ? self::badge('Funziona', 'ok') : self::badge('Non riuscita', 'danger') . ' ' . self::e($ai_status['error'])) . '</p>';
                if (!empty($ai_status['models'])) { echo '<p class="sn-hint">Modelli Flash disponibili con questa chiave: <code>' . implode('</code> · <code>', array_map('esc_html', $ai_status['models'])) . '</code></p>'; }
            }
        } else { echo '<p class="sn-muted">Solo gli amministratori possono configurare la chiave API.</p>'; }
        echo '</section>';

        $b = Integra_Sentinel_Settings::get('branding');
        echo '<section class="sn-panel" id="sn-branding"><h3>Marchio dei report clienti</h3>';
        Integra_Sentinel_Admin::form_start('branding', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-branding'));
        echo '<div class="sn-form-grid"><label>Nome dell’agenzia<input type="text" name="name" value="' . esc_attr($b['name']) . '"></label><label>Sito dell’agenzia<input type="url" name="url" value="' . esc_attr($b['url']) . '"></label>';
        echo '<label>Logo (URL HTTPS di un’immagine)<input type="url" name="logo" value="' . esc_attr($b['logo']) . '"></label><label>Colore principale<input type="color" name="color" value="' . esc_attr($b['color']) . '"></label>';
        echo '<label>Mittente delle email ai clienti<input type="text" name="from_name" value="' . esc_attr($b['from_name']) . '" placeholder="Integra · Assistenza siti"></label><label>Piè di pagina<input type="text" name="footer" value="' . esc_attr($b['footer']) . '"></label></div>';
        submit_button('Salva marchio', 'secondary', 'submit', false);
        echo '</form></section>';

        $roles = Integra_Sentinel_Settings::get('roles');
        echo '<section class="sn-panel" id="sn-access"><h3>Accessi e sicurezza del Master</h3>';
        $twofa = self::two_factor_plugin();
        echo '<p>' . ($twofa ? self::badge('2FA: ' . $twofa, 'ok') : self::badge('Nessun plugin di autenticazione a due fattori rilevato', 'warn')) . '</p>';
        echo '<p class="sn-hint">Il Master conosce tutti i siti dei clienti: proteggi gli accessi con la verifica in due passaggi. Dai siti arrivano solo i nomi utente degli amministratori, non le email; se un Client ne inviasse, il Master le maschera.</p>';
        if ($admin) {
            Integra_Sentinel_Admin::form_start('roles', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-access'));
            echo '<table class="sn-simple-table"><thead><tr><th>Ruolo</th><th>Vede Sentinel</th><th>Gestisce (interventi, eccezioni, licenze, impostazioni)</th></tr></thead><tbody>';
            foreach (wp_roles()->get_names() as $key => $label) {
                if ($key === 'administrator') { echo '<tr><td>' . self::e(translate_user_role($label)) . '</td><td>Sempre</td><td>Sempre</td></tr>'; continue; }
                echo '<tr><td>' . self::e(translate_user_role($label)) . '</td><td><input type="checkbox" name="view[]" value="' . esc_attr($key) . '"' . checked(in_array($key, (array) $roles['view'], true), true, false) . ' aria-label="Visione per ' . esc_attr($label) . '"></td><td><input type="checkbox" name="manage[]" value="' . esc_attr($key) . '"' . checked(in_array($key, (array) $roles['manage'], true), true, false) . ' aria-label="Gestione per ' . esc_attr($label) . '"></td></tr>';
            }
            echo '</tbody></table><p class="sn-hint">Aggiungere o rimuovere siti, chiavi, token e canali di notifica resta riservato agli amministratori.</p>';
            submit_button('Salva accessi', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</section>';

        echo '<section class="sn-panel" id="sn-audit"><h3>Registro attività</h3><p class="sn-hint">Chi ha fatto cosa sul Master (ultime 150 operazioni).</p>';
        $entries = Integra_Sentinel_Store::audit_entries(150);
        $sites = Integra_Sentinel_Master::sites();
        if (!$entries) { echo '<p class="sn-muted">Nessuna operazione registrata.</p>'; }
        else {
            echo '<div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Data</th><th>Utente</th><th>Operazione</th><th>Sito</th><th>Dettaglio</th></tr></thead><tbody>';
            foreach ($entries as $row) {
                echo '<tr><td>' . self::e(wp_date('d/m/Y H:i', (int) $row['at'])) . '</td><td>' . self::e($row['user_login']) . '</td><td><code>' . self::e($row['action']) . '</code></td><td>' . self::e($row['site_id'] !== '' ? ($sites[$row['site_id']]['label'] ?? $row['site_id']) : '—') . '</td><td>' . self::e($row['detail']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        Integra_Sentinel_Admin::form_start('daily_now', 'integra-sentinel-settings', array('_sn_anchor' => 'sn-audit'));
        echo '<p class="sn-hint">I lavori giornalieri (andamenti, promemoria licenze, chiusura interventi, pulizia archivio) partono da soli una volta al giorno. <button class="button button-small" type="submit">Eseguili adesso</button></p></form>';
        echo '</section>';
        Integra_Sentinel_Admin::close();
    }

    private static function two_factor_plugin() {
        if (!function_exists('is_plugin_active')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $known = array('two-factor/two-factor.php' => 'Two Factor', 'wp-2fa/wp-2fa.php' => 'WP 2FA', 'wordfence/wordfence.php' => 'Wordfence Login Security',
            'wordfence-login-security/wordfence-login-security.php' => 'Wordfence Login Security', 'two-factor-authentication/two-factor-login.php' => 'Two Factor Authentication',
            'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' => 'miniOrange 2FA', 'better-wp-security/better-wp-security.php' => 'Solid Security',
            'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security', 'google-authenticator/google-authenticator.php' => 'Google Authenticator');
        foreach ($known as $file => $name) { if (is_plugin_active($file)) { return $name; } }
        return '';
    }

    /* ---------- Pannelli della scheda sito ---------- */

    /** Scheda cliente: contatti, ondata, tag, finestra di manutenzione e pagine chiave da controllare (l'hosting e' nella checklist). */
    public static function site_meta_form($site) {
        $meta = (array) ($site['meta'] ?? array());
        $pages = array_values((array) ($meta['pages'] ?? array()));
        echo '<details class="sn-panel sn-config" id="sn-meta"><summary><span><strong>Scheda cliente</strong><small>Contatti, ondata di aggiornamento, tag, finestra di manutenzione e pagine chiave da controllare</small></span><span class="sn-chevron">⌄</span></summary><div class="sn-config-body">';
        if (!Integra_Sentinel_Settings::can_manage()) { echo '<p class="sn-muted">Sola visione.</p></div></details>'; return; }
        Integra_Sentinel_Admin::form_start('site_meta', 'integra-sentinel', array('site_id' => $site['id'], '_sn_site' => $site['id'], '_sn_anchor' => 'sn-meta'));
        echo '<div class="sn-form-grid">';
        echo '<label>Referente del cliente<input type="text" name="contact_name" maxlength="80" value="' . esc_attr($meta['contact_name'] ?? '') . '"></label>';
        echo '<label>Email del referente<input type="email" name="contact_email" value="' . esc_attr($meta['contact_email'] ?? '') . '"></label>';
        echo '<label>Ondata di aggiornamento<select name="ring">';
        foreach (Integra_Sentinel_Fleet::ring_labels() as $ring => $label) { echo '<option value="' . (int) $ring . '"' . selected((int) ($meta['ring'] ?? 2), $ring, false) . '>' . self::e($label) . '</option>'; }
        echo '</select></label>';
        echo '<label>Tag (separati da virgola)<input type="text" name="tags" value="' . esc_attr(implode(', ', (array) ($meta['tags'] ?? array()))) . '" placeholder="ecommerce, contratto-pro"></label>';
        echo '<label>Contratto<input type="text" name="contract" maxlength="60" value="' . esc_attr($meta['contract'] ?? '') . '" placeholder="Manutenzione Pro"></label>';
        echo '<label>Finestra di manutenzione<input type="text" name="window" maxlength="80" value="' . esc_attr($meta['window'] ?? '') . '" placeholder="mar–gio 8:00–10:00"></label>';
        echo '<label class="sn-inline-check"><input type="checkbox" name="probe_off" value="1"' . checked(!empty($meta['probe_off']), true, false) . '> Non controllare la raggiungibilità di questo sito</label>';
        echo '<label class="sn-span-2">Testo che deve comparire nella home (facoltativo)<input type="text" name="home_expect" maxlength="120" value="' . esc_attr($meta['home_expect'] ?? '') . '" placeholder="es. il nome dell’azienda nel footer"></label>';
        echo '<fieldset class="sn-span-2"><legend>Pagine chiave controllate insieme alla home (stesso dominio)</legend>';
        for ($i = 0; $i < 3; ++$i) {
            $pg = (array) ($pages[$i] ?? array());
            echo '<div class="sn-page-row"><input type="text" name="page_label[]" maxlength="40" placeholder="Etichetta (es. Carrello)" value="' . esc_attr($pg['label'] ?? '') . '" aria-label="Etichetta pagina ' . ($i + 1) . '"><input type="url" name="page_url[]" placeholder="' . esc_attr(trailingslashit($site['url'])) . 'carrello/" value="' . esc_attr($pg['url'] ?? '') . '" aria-label="Indirizzo pagina ' . ($i + 1) . '"><input type="text" name="page_expect[]" maxlength="120" placeholder="Testo atteso (facoltativo)" value="' . esc_attr($pg['expect'] ?? '') . '" aria-label="Testo atteso pagina ' . ($i + 1) . '"></div>';
        }
        echo '</fieldset><label class="sn-span-2">Note tecniche<textarea name="notes" rows="3" maxlength="2000">' . esc_textarea($meta['notes'] ?? '') . '</textarea></label></div>';
        submit_button('Salva scheda cliente', 'primary', 'submit', false);
        echo '</form></div></details>';
    }

    /** Ambiente del server: cosa impedisce agli aggiornamenti di funzionare. */
    public static function environment_panel($analysis) {
        $checks = $analysis['environment']['checks'];
        echo '<section class="sn-panel sn-envpanel"><div class="sn-section-head"><div><h3>Aggiornabilità del server</h3><p>Permessi, spazio, memoria, WP-Cron, backup e fine supporto: se qui qualcosa non va, gli aggiornamenti falliscono anche quando sono “pronti”.</p></div></div><div class="sn-check-grid">';
        foreach ($checks as $c) {
            echo '<div class="sn-check sn-check-' . esc_attr($c['tone']) . '"><span class="sn-check-label">' . self::e($c['label']) . '</span><strong>' . self::e($c['value']) . '</strong>' . ($c['hint'] !== '' ? '<small>' . self::e($c['hint']) . '</small>' : '') . '</div>';
        }
        $core = $analysis['core'];
        $core_text = array('ok' => array('Core verificato', 'ok', $core['checked'] . ' file identici ai checksum ufficiali di WordPress.org.'), 'modified' => array('Core modificato', 'danger', implode(', ', array_slice($core['mismatch'], 0, 4))),
            'pending' => array('In attesa', 'muted', 'Checksum ufficiali non ancora scaricati dal Master.'), 'unavailable' => array('Non verificabile', 'muted', 'Checksum non disponibili per questa versione.'),
            'unknown' => array('Dati non disponibili', 'muted', 'Serve il Client 1.7.'));
        $ct = $core_text[$core['state']] ?? $core_text['unknown'];
        echo '<div class="sn-check sn-check-' . esc_attr($ct[1]) . '"><span class="sn-check-label">File del core</span><strong>' . self::e($ct[0]) . '</strong><small>' . self::e($ct[2]) . '</small></div>';
        echo '</div></section>';
    }

    /** Temi: origine, licenza, child theme, personalizzazioni, plugin inclusi. */
    public static function themes_panel($analysis) {
        $rows = array_values(array_filter($analysis['themes']['themes'], function($t) { return $t['role'] !== ''; }));
        $inactive = count($analysis['themes']['themes']) - count($rows);
        echo '<section class="sn-panel sn-themes"><div class="sn-section-head"><div><h3>Temi in uso</h3><p>Tema attivo e tema genitore: canale di aggiornamento, licenza, child theme e personalizzazioni.</p></div>' . ($inactive ? self::badge($inactive . ' inattivi', 'muted') : '') . '</div>';
        if (!$rows) { echo '<p class="sn-muted">Dati del tema non disponibili.</p></section>'; return; }
        echo '<ul class="sn-origin-list">';
        foreach ($rows as $t) {
            $to = $t['update'] !== '' ? $t['update'] : $t['latest'];
            echo '<li><div><strong>' . self::e($t['name']) . '</strong> ' . self::badge($t['role'] === 'active' ? ($t['parent'] !== '' ? 'Child theme attivo' : 'Tema attivo') : 'Tema genitore', 'brand')
                . ($t['premium'] ? ' ' . self::badge($t['envato'] ? 'Envato' : 'Premium', 'premium') : '') . '<small>' . self::e($t['originLabel']) . '</small>';
            foreach (array_slice($t['reasons'], 0, 3) as $reason) { echo '<small>' . self::e($reason) . '</small>'; }
            if ($t['noChild']) { echo '<small>Nessun child theme: eventuali modifiche ai file del tema andrebbero perse con l’aggiornamento.</small>'; }
            echo '</div><div class="sn-origin-ver">' . Integra_Sentinel_Master::sn_version_step($t['version'], $to !== $t['version'] ? $to : '') . Integra_Sentinel_Master::sn_badge(Integra_Sentinel_Dependencies::status_label($t['status']), Integra_Sentinel_Master::sn_status_tone($t['status'])) . '</div></li>';
        }
        echo '</ul></section>';
    }

    /** Raggiungibilità della home e delle pagine chiave, controllata dal Master. */
    public static function probe_panel($site) {
        $state = Integra_Sentinel_Probe::state()[$site['id']] ?? array();
        $pages = Integra_Sentinel_Probe::pages($site);
        $u7 = Integra_Sentinel_Store::uptime($site['id'], time() - 7 * DAY_IN_SECONDS);
        $u30 = Integra_Sentinel_Store::uptime($site['id'], time() - 30 * DAY_IN_SECONDS);
        $meta = (array) ($site['meta'] ?? array());
        echo '<section class="sn-panel sn-probe" id="sn-probe"><div class="sn-section-head"><div><h3>Raggiungibilità e pagine chiave</h3><p>Il Master visita la home e le pagine chiave come un normale visitatore (sola lettura) e avvisa solo dopo 3 controlli falliti di fila, ognuno ripetuto subito una seconda volta. Un sito che risponde con un blocco del firewall (401, 403, 429) conta come online.</p></div>';
        if (Integra_Sentinel_Settings::can_manage()) {
            Integra_Sentinel_Admin::form_start('probe_now', 'integra-sentinel', array('site_id' => $site['id'], '_sn_site' => $site['id'], '_sn_anchor' => 'sn-probe'));
            echo '<button class="button" type="submit">Verifica adesso</button></form>';
        }
        echo '</div>';
        if (!empty($meta['probe_off'])) { echo '<p class="sn-hint">Controllo automatico disattivato nella scheda cliente.</p>'; }
        echo '<div class="sn-check-grid">';
        echo '<div class="sn-check sn-check-' . ($u7 ? ($u7['pct'] >= 99.5 ? 'ok' : 'warn') : 'muted') . '"><span class="sn-check-label">Ultimi 7 giorni</span><strong>' . ($u7 ? self::e(number_format_i18n($u7['pct'], 2)) . '%' : 'n.d.') . '</strong><small>' . ($u7 ? (int) $u7['checks'] . ' controlli · ' . (int) $u7['ms'] . ' ms medi' : 'Nessun controllo registrato') . '</small></div>';
        echo '<div class="sn-check sn-check-' . ($u30 ? ($u30['pct'] >= 99.5 ? 'ok' : 'warn') : 'muted') . '"><span class="sn-check-label">Ultimi 30 giorni</span><strong>' . ($u30 ? self::e(number_format_i18n($u30['pct'], 2)) . '%' : 'n.d.') . '</strong><small>' . ($u30 ? (int) $u30['checks'] . ' controlli' : '—') . '</small></div>';
        foreach ($pages as $idx => $pg) {
            $r = $state['pages'][$idx] ?? null;
            $tone = $r === null ? 'muted' : (!empty($r['ok']) ? 'ok' : 'danger');
            echo '<div class="sn-check sn-check-' . $tone . '"><span class="sn-check-label">' . self::e($pg['label']) . '</span><strong>' . ($r === null ? 'Non ancora controllata' : (!empty($r['ok']) ? 'Raggiungibile' : 'Problema')) . '</strong><small>'
                . self::e($r === null ? $pg['url'] : ('HTTP ' . (int) $r['code'] . ' · ' . (int) $r['ms'] . ' ms' . (!empty($r['error']) && empty($r['ok']) ? ' · ' . $r['error'] : ''))) . '</small></div>';
        }
        echo '</div>' . (!empty($state['last_at']) ? '<p class="sn-hint">Ultimo controllo: ' . self::e(wp_date('d/m/Y H:i', (int) $state['last_at'])) . '</p>' : '') . '</section>';
    }

    /** Testi pronti per il cliente e per l'hosting. */
    public static function requests_panel($site) {
        $req = Integra_Sentinel_Reports::requests($site);
        if ($req['client'] === '' && $req['hosting'] === '') { return; }
        $meta = (array) ($site['meta'] ?? array());
        echo '<section class="sn-panel sn-requests" id="sn-requests"><div class="sn-section-head"><div><h3>Richieste da inviare</h3><p>Testi pronti con le azioni che spettano al cliente o all’hosting. Copiali nel tuo programma di posta: Sentinel non invia nulla a terzi.</p></div></div><div class="sn-two">';
        foreach (array('client' => 'Al cliente', 'hosting' => 'All’hosting') as $key => $label) {
            if ($req[$key] === '') { continue; }
            $id = 'sn-req-' . $key;
            echo '<div><label for="' . esc_attr($id) . '"><strong>' . self::e($label) . '</strong></label><textarea id="' . esc_attr($id) . '" class="large-text" rows="9" readonly>' . esc_textarea($req[$key]) . '</textarea><p><button type="button" class="button" data-sn-copy="' . esc_attr($id) . '">Copia il testo</button>';
            if ($key === 'client' && !empty($meta['contact_email']) && is_email($meta['contact_email'])) {
                echo ' <a class="button" href="' . esc_url('mailto:' . rawurlencode($meta['contact_email']) . '?subject=' . rawurlencode($req['subject'])) . '">Apri una email al referente</a>';
            }
            echo '</p></div>';
        }
        echo '</div></section>';
    }

    /** Codice di abbinamento appena generato (visibile una volta, all'utente che l'ha creato). */
    public static function pairing_notice($site) {
        $once = get_transient('integra_sentinel_pair_once_' . get_current_user_id());
        if (!is_array($once) || ($once['site'] ?? '') !== $site['id']) { return; }
        delete_transient('integra_sentinel_pair_once_' . get_current_user_id());
        $code = (string) $once['code'];
        echo '<section class="sn-panel sn-secret"><h2>Codice di abbinamento</h2><p>Nel sito del cliente apri Impostazioni → Integra Sentinel → “Collegamento rapido con codice” e inserisci l’URL del Master e questo codice. Vale 30 minuti, una sola volta, solo per ' . self::e($site['url']) . '.</p>';
        echo '<div class="sn-secret-line"><strong>URL Master</strong><code>' . self::e(untrailingslashit(home_url('/'))) . '</code></div><div class="sn-secret-line"><strong>Codice</strong><code class="sn-pair-code">' . self::e(implode('-', str_split($code, 4))) . '</code></div>';
        echo '<p class="sn-hint">Con WP-CLI sul sito del cliente: <code>wp sentinel-client pair ' . self::e(untrailingslashit(home_url('/'))) . ' ' . self::e(implode('-', str_split($code, 4))) . '</code></p></section>';
    }
}
