<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * TablePress integration for NIBWP (Pro tier).
 *
 * Replaces the four actions this carried in plugin-integrations.php — list,
 * get, create, update — which could build a table and replace it wholesale but
 * could not delete one, copy one, or change a single cell without resending
 * the entire grid. Editing one price in a 200-row table meant round-tripping
 * 200 rows and hoping nothing was dropped on the way.
 *
 * Everything goes through TablePress's own table model, so its sanitising,
 * caching and last-modified bookkeeping still run.
 *
 * REQUIRES: TablePress active.
 */

/** @return WP_Error|null */
function nibwp_tp_guard(): ?WP_Error
{
    if (!class_exists('TablePress')) {
        return new WP_Error(
            'nibwp_tp_missing',
            __('TablePress is not active on this site.', domain: 'nibwp')
        );
    }

    return null;
}

/** The table model, or an error explaining why not. */
function nibwp_tp_model()
{
    if (!method_exists('TablePress', 'load_model')) {
        return new WP_Error('nibwp_tp_no_model', __('This TablePress version does not expose its table model.', domain: 'nibwp'));
    }

    return TablePress::load_model('table');
}

/** Summary shape — the grid itself is only returned when asked for. */
function nibwp_tp_summary(array $table): array
{
    $data = (array) ($table['data'] ?? []);

    return [
        'id'            => (string) ($table['id'] ?? ''),
        'name'          => (string) ($table['name'] ?? ''),
        'description'   => (string) ($table['description'] ?? ''),
        'rows'          => count($data),
        'columns'       => count((array) ($data[0] ?? [])),
        'last_modified' => (string) ($table['last_modified'] ?? ''),
        'shortcode'     => '[table id=' . ($table['id'] ?? '') . ' /]',
    ];
}

wp_register_ability('nibwp/tablepress-manage', [
    'label' => __('TablePress – Tables & Cells', domain: 'nibwp'),
    'description' => __(
        'Manage TablePress tables: create, read, copy, rename and delete them, replace the whole grid, or edit individual cells, rows and columns without resending the rest.',
        domain: 'nibwp',
    ),
    'category' => 'utilities',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Tables
                    'list_tables', 'get_table', 'create_table', 'update_table', 'delete_table',
                    'copy_table', 'rename_table', 'count_tables',
                    // Grid editing
                    'set_cell', 'add_row', 'update_row', 'delete_row',
                    'add_column', 'delete_column',
                    // Options
                    'get_options', 'update_options',
                ],
                'description' => 'The operation to perform.',
            ],

            'table_id'   => ['type' => 'string', 'description' => 'TablePress table ID. These are strings, not integers.'],
            'name'       => ['type' => 'string', 'description' => 'Table name.'],
            'description' => ['type' => 'string', 'description' => 'Table description.'],

            'data' => [
                'type' => 'array',
                'description' => 'Whole grid as an array of rows, each row an array of cell strings. Replaces everything.',
                'items' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'row' => [
                'type' => 'array',
                'description' => 'A single row of cell strings, for add_row and update_row.',
                'items' => ['type' => 'string'],
            ],
            'row_index'    => ['type' => 'integer', 'description' => 'Zero-based row index. Row 0 is the header when table_head is on.'],
            'column_index' => ['type' => 'integer', 'description' => 'Zero-based column index.'],
            'value'        => ['type' => 'string', 'description' => 'Cell contents for set_cell.'],
            'column_label' => ['type' => 'string', 'description' => 'Header text for add_column.'],

            'options' => ['type' => 'object', 'description' => 'TablePress table options to merge, e.g. table_head, use_datatables.'],

            'include_data' => ['type' => 'boolean', 'default' => false, 'description' => 'Return the full grid from get_table. Off by default because large tables are large.'],
            'per_page'     => ['type' => 'integer', 'default' => 25],
            'page'         => ['type' => 'integer', 'default' => 1],

            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required for delete_table, delete_row and delete_column.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_tablepress_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage TablePress tables.',
                '',
                'Table IDs are STRINGS, and TablePress reuses them — a table deleted and',
                'recreated may not carry the same ID, so read list_tables rather than',
                'assuming one.',
                '',
                'Prefer set_cell, update_row and add_row over update_table. update_table',
                'replaces the entire grid, so a partial payload silently discards every',
                'row you did not send.',
                '',
                'Row 0 is the header row when the table has table_head enabled, so a',
                'delete_row of 0 removes the headings rather than the first record.',
                '',
                'IRREVERSIBLE — needs confirm=true: delete_table, delete_row, delete_column.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_tablepress_manage(array $input): array|WP_Error
{
    if ($guard = nibwp_tp_guard()) {
        return $guard;
    }

    $model = nibwp_tp_model();
    if (is_wp_error($model)) {
        return $model;
    }

    $action = (string) ($input['action'] ?? '');

    $irreversible = ['delete_table', 'delete_row', 'delete_column'];
    if (in_array($action, $irreversible, strict: true) && empty($input['confirm'])) {
        return new WP_Error(
            'nibwp_tp_unconfirmed',
            __('This cannot be undone. Re-issue the call with confirm set to true if that is intended.', domain: 'nibwp')
        );
    }

    try {
        return nibwp_tp_dispatch($model, $action, $input);
    } catch (\Throwable $e) {
        return new WP_Error('nibwp_tp_failed', sprintf(
            /* translators: 1: action, 2: error message. */
            __('TablePress could not complete %1$s: %2$s', domain: 'nibwp'),
            $action,
            $e->getMessage()
        ));
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function nibwp_tp_dispatch($model, string $action, array $in): array|WP_Error
{
    // Actions that need an existing table all load it the same way.
    $needs_table = [
        'get_table', 'update_table', 'delete_table', 'copy_table', 'rename_table',
        'set_cell', 'add_row', 'update_row', 'delete_row', 'add_column', 'delete_column',
        'get_options', 'update_options',
    ];

    $table = null;
    if (in_array($action, $needs_table, strict: true)) {
        $id = (string) ($in['table_id'] ?? '');
        if ($id === '') {
            return new WP_Error('nibwp_tp_no_id', __('This action needs a table_id.', domain: 'nibwp'));
        }
        if (!$model->table_exists($id)) {
            return new WP_Error('nibwp_tp_no_table', sprintf(
                /* translators: %s: the table ID that was asked for. */
                __('No TablePress table with ID %s.', domain: 'nibwp'),
                $id
            ));
        }
        $table = $model->load($id, true, true);
        if (is_wp_error($table)) {
            return $table;
        }
    }

    switch ($action) {
        case 'count_tables':
            return ['tables' => (int) $model->count_tables()];

        case 'list_tables':
            $ids      = (array) $model->load_all(false);
            $per_page = max(1, min(200, (int) ($in['per_page'] ?? 25)));
            $offset   = (max(1, (int) ($in['page'] ?? 1)) - 1) * $per_page;

            $tables = [];
            foreach (array_slice($ids, $offset, $per_page) as $id) {
                $loaded = $model->load((string) $id, false, false);
                if (!is_wp_error($loaded)) {
                    $tables[] = nibwp_tp_summary((array) $loaded);
                }
            }

            return ['tables' => $tables, 'total' => count($ids)];

        case 'get_table':
            $out = ['table' => nibwp_tp_summary((array) $table)];
            if (!empty($in['include_data'])) {
                $out['data'] = (array) ($table['data'] ?? []);
            }

            return $out;

        case 'create_table':
            $name = (string) ($in['name'] ?? '');
            if ($name === '') {
                return new WP_Error('nibwp_tp_no_name', __('create_table needs a name.', domain: 'nibwp'));
            }

            $new = $model->get_table_template();
            $new['name']        = $name;
            $new['description'] = (string) ($in['description'] ?? '');
            if (!empty($in['data']) && is_array($in['data'])) {
                $new['data'] = nibwp_tp_normalise_grid((array) $in['data']);
            }

            $id = $model->add($new);
            if (is_wp_error($id)) {
                return $id;
            }

            $created = $model->load((string) $id, true, true);

            return ['created' => true, 'table' => nibwp_tp_summary((array) $created)];

        case 'update_table':
            if (empty($in['data']) || !is_array($in['data'])) {
                return new WP_Error('nibwp_tp_no_data', __('update_table replaces the whole grid, so it needs data. To change one value use set_cell.', domain: 'nibwp'));
            }
            $before = count((array) ($table['data'] ?? []));
            $table['data'] = nibwp_tp_normalise_grid((array) $in['data']);
            if (isset($in['name'])) {
                $table['name'] = (string) $in['name'];
            }
            if (isset($in['description'])) {
                $table['description'] = (string) $in['description'];
            }

            $saved = $model->save($table);
            if (is_wp_error($saved)) {
                return $saved;
            }

            return [
                'updated'   => true,
                'table'     => nibwp_tp_summary($table),
                'rows_before' => $before,
                'rows_after'  => count($table['data']),
            ];

        case 'delete_table':
            $deleted = $model->delete((string) $table['id']);
            if (is_wp_error($deleted)) {
                return $deleted;
            }

            return ['deleted' => true, 'table_id' => (string) $table['id']];

        case 'copy_table':
            $copy_id = $model->copy((string) $table['id']);
            if (is_wp_error($copy_id)) {
                return $copy_id;
            }

            return ['copied' => true, 'from' => (string) $table['id'], 'table_id' => (string) $copy_id];

        case 'rename_table':
            $new_id = (string) ($in['name'] ?? '');
            if ($new_id === '') {
                return new WP_Error('nibwp_tp_no_new_id', __('rename_table needs the new ID in `name`.', domain: 'nibwp'));
            }
            $renamed = $model->change_table_id((string) $table['id'], $new_id);
            if (is_wp_error($renamed)) {
                return $renamed;
            }

            return [
                'renamed'   => true,
                'table_id'  => $new_id,
                'note'      => __('Any [table id=…] shortcode using the old ID now points at nothing. Update those too.', domain: 'nibwp'),
            ];

        case 'get_options':
            return ['options' => (array) ($table['options'] ?? [])];

        case 'update_options':
            $options = (array) ($in['options'] ?? []);
            if ($options === []) {
                return new WP_Error('nibwp_tp_no_options', __('update_options needs an options object.', domain: 'nibwp'));
            }
            $table['options'] = array_merge((array) ($table['options'] ?? []), $options);
            $saved = $model->save($table);
            if (is_wp_error($saved)) {
                return $saved;
            }

            return ['updated' => true, 'options' => (array) $table['options']];
    }

    // Everything left edits the grid.
    return nibwp_tp_grid($model, $action, $in, $table);
}

/**
 * Cell, row and column editing.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_tp_grid($model, string $action, array $in, array $table): array|WP_Error
{
    $data = (array) ($table['data'] ?? []);
    $rows = count($data);
    $cols = count((array) ($data[0] ?? []));

    $row_index = isset($in['row_index']) ? (int) $in['row_index'] : null;
    $col_index = isset($in['column_index']) ? (int) $in['column_index'] : null;

    switch ($action) {
        case 'set_cell':
            if ($row_index === null || $col_index === null) {
                return new WP_Error('nibwp_tp_no_cell', __('set_cell needs row_index and column_index.', domain: 'nibwp'));
            }
            if ($row_index < 0 || $row_index >= $rows || $col_index < 0 || $col_index >= $cols) {
                return new WP_Error('nibwp_tp_out_of_range', sprintf(
                    /* translators: 1: rows, 2: columns. */
                    __('That cell is outside the table, which is %1$d rows by %2$d columns.', domain: 'nibwp'),
                    $rows,
                    $cols
                ));
            }
            $was = (string) $data[$row_index][$col_index];
            $data[$row_index][$col_index] = (string) ($in['value'] ?? '');
            break;

        case 'add_row':
            $row = array_values((array) ($in['row'] ?? []));
            if ($row === []) {
                return new WP_Error('nibwp_tp_no_row', __('add_row needs a row.', domain: 'nibwp'));
            }
            // Pad or trim so the grid stays rectangular; a ragged row breaks
            // TablePress rendering rather than erroring.
            $row = array_pad(array_slice($row, 0, $cols), $cols, '');
            if ($row_index === null || $row_index >= $rows) {
                $data[] = $row;
            } else {
                array_splice($data, max(0, $row_index), 0, [$row]);
            }
            break;

        case 'update_row':
            if ($row_index === null) {
                return new WP_Error('nibwp_tp_no_row_index', __('update_row needs a row_index.', domain: 'nibwp'));
            }
            if ($row_index < 0 || $row_index >= $rows) {
                return new WP_Error('nibwp_tp_out_of_range', __('That row is outside the table.', domain: 'nibwp'));
            }
            $row = array_values((array) ($in['row'] ?? []));
            if ($row === []) {
                return new WP_Error('nibwp_tp_no_row', __('update_row needs a row.', domain: 'nibwp'));
            }
            $data[$row_index] = array_pad(array_slice($row, 0, $cols), $cols, '');
            break;

        case 'delete_row':
            if ($row_index === null) {
                return new WP_Error('nibwp_tp_no_row_index', __('delete_row needs a row_index.', domain: 'nibwp'));
            }
            if ($row_index < 0 || $row_index >= $rows) {
                return new WP_Error('nibwp_tp_out_of_range', __('That row is outside the table.', domain: 'nibwp'));
            }
            if ($rows <= 1) {
                return new WP_Error('nibwp_tp_last_row', __('A TablePress table cannot have zero rows. Delete the table instead.', domain: 'nibwp'));
            }
            array_splice($data, $row_index, 1);
            break;

        case 'add_column':
            $label = (string) ($in['column_label'] ?? '');
            $at = $col_index === null || $col_index > $cols ? $cols : max(0, $col_index);
            foreach ($data as $i => $r) {
                $cell = $i === 0 ? $label : '';
                $r = (array) $r;
                array_splice($r, $at, 0, [$cell]);
                $data[$i] = $r;
            }
            break;

        case 'delete_column':
            if ($col_index === null) {
                return new WP_Error('nibwp_tp_no_col_index', __('delete_column needs a column_index.', domain: 'nibwp'));
            }
            if ($col_index < 0 || $col_index >= $cols) {
                return new WP_Error('nibwp_tp_out_of_range', __('That column is outside the table.', domain: 'nibwp'));
            }
            if ($cols <= 1) {
                return new WP_Error('nibwp_tp_last_column', __('A TablePress table cannot have zero columns. Delete the table instead.', domain: 'nibwp'));
            }
            foreach ($data as $i => $r) {
                $r = (array) $r;
                array_splice($r, $col_index, 1);
                $data[$i] = $r;
            }
            break;

        default:
            return new WP_Error('nibwp_tp_unknown_action', sprintf(
                /* translators: %s: the requested action. */
                __('Unknown TablePress action: %s', domain: 'nibwp'),
                $action
            ));
    }

    $table['data'] = array_values($data);
    $saved = $model->save($table);
    if (is_wp_error($saved)) {
        return $saved;
    }

    return [
        'updated' => true,
        'table'   => nibwp_tp_summary($table),
        'was'     => $was ?? null,
    ];
}

/**
 * Force a rectangular grid of strings.
 *
 * TablePress renders a ragged grid as a broken table rather than refusing it,
 * so a short row is padded to the widest one instead of being trusted.
 *
 * @param array<int, mixed> $grid
 * @return array<int, array<int, string>>
 */
function nibwp_tp_normalise_grid(array $grid): array
{
    $rows = [];
    $width = 0;

    foreach ($grid as $row) {
        $row = array_values(array_map(static fn($c) => (string) $c, (array) $row));
        $width = max($width, count($row));
        $rows[] = $row;
    }

    if ($rows === []) {
        return [['']];
    }

    foreach ($rows as $i => $row) {
        $rows[$i] = array_pad($row, $width, '');
    }

    return $rows;
}
