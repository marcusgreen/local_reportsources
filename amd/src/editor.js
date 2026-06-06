// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * CodeMirror 6 SQL editor with schema-aware autocomplete for local_reportsources.
 *
 * Authored as ES6 in editor.es6.js; this file is the AMD-format build that
 * Moodle's source loader serves when $CFG->cachejs is false. Keep both in sync.
 *
 * @module     local_reportsources/editor
 * @copyright  2026 Marcus Green
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define("local_reportsources/editor", ["exports", "./codemirror-lazy"], (function(_exports, _cm) {
    Object.defineProperty(_exports, "__esModule", {value: !0});
    _exports.init = void 0;
    _exports.init = function(targetid) {
        const textarea = document.getElementById(targetid);
        if (!textarea) {
            return;
        }

        const schemaElement = document.getElementById("tablejson");
        let schema = {};
        if (schemaElement) {
            try {
                schema = JSON.parse(schemaElement.value);
            } catch (e) {
                // Schema unavailable; autocomplete still works for SQL keywords.
            }
        }

        const fkElement = document.getElementById("fkjson");
        let fkMap = {};
        if (fkElement) {
            try {
                fkMap = JSON.parse(fkElement.value);
            } catch (e) {
                // FK map unavailable; column FK annotations won't show.
            }
        }

        const tables = Object.keys(schema).map(name => ({label: name}));

        const aliasSkip = new Set([
            "where", "on", "set", "inner", "outer", "left", "right",
            "cross", "full", "group", "order", "having", "limit",
            "union", "except", "intersect", "using",
        ]);

        /**
         * Parse FROM/JOIN clauses and return a map of alias → {table, cols}.
         *
         * @param {string} docText - Full document text from the CodeMirror state.
         * @returns {Object} Map of lowercase alias string to {table: string, cols: string[]}.
         */
        function parseAliases(docText) {
            const map = {};
            const re = /\b(?:FROM|JOIN)\s+\{?(\w+)\}?\s+(?:AS\s+)?(\w+)/gi;
            let m;
            while ((m = re.exec(docText)) !== null) {
                const raw = m[1].toLowerCase();
                const alias = m[2].toLowerCase();
                if (aliasSkip.has(alias)) {
                    continue;
                }
                const resolvedTable = Object.keys(schema).find(k => k.toLowerCase() === raw) ?? raw;
                const cols = schema[resolvedTable];
                if (cols) {
                    map[alias] = {table: resolvedTable, cols};
                }
            }
            return map;
        }

        /**
         * CompletionSource that resolves alias.column completions live from the doc.
         * FK columns are annotated with "→ reftable.refcol" in the detail field.
         *
         * @param {CompletionContext} context - CodeMirror completion context.
         * @returns {CompletionResult|null} Completion result, or null when not applicable.
         */
        function aliasCompletionSource(context) {
            const before = context.matchBefore(/\w+\.\w*/);
            if (!before) {
                return null;
            }
            const dotIdx = before.text.indexOf(".");
            const alias = before.text.slice(0, dotIdx).toLowerCase();
            if (schema[alias] !== undefined) {
                return null;
            }
            const aliasMap = parseAliases(context.state.doc.toString());
            const entry = aliasMap[alias];
            if (!entry || !entry.cols.length) {
                return null;
            }
            const tableFkMap = fkMap[entry.table] || {};
            return {
                from: before.from + dotIdx + 1,
                options: entry.cols.map(col => {
                    const fk = tableFkMap[col];
                    return Object.assign({label: col, type: "property"}, fk ? {detail: "→ " + fk.reftable + "." + fk.refcol} : {});
                }),
                validFor: /^\w*$/,
            };
        }

        const heightTheme = _cm.EditorView.theme({
            "&": {height: "260px"},
            ".cm-scroller": {overflow: "auto"},
        });

        const state = _cm.EditorState.create({
            doc: textarea.value,
            extensions: [
                _cm.basicSetup,
                (0, _cm.sql)({dialect: _cm.MySQL, schema: schema, tables: tables, upperCaseKeywords: !0}),
                _cm.MySQL.language.data.of({autocomplete: aliasCompletionSource}),
                _cm.keymap.of([
                    {key: "Tab", run: _cm.acceptCompletion},
                ]),
                _cm.EditorView.lineWrapping,
                heightTheme,
                _cm.EditorView.updateListener.of(update => {
                    if (update.docChanged) {
                        textarea.value = update.state.doc.toString();
                    }
                }),
            ],
        });

        const container = document.createElement("div");
        container.className = "codemirror-sql-editor";
        container.style.width = "100%";
        textarea.parentNode.insertBefore(container, textarea);
        textarea.style.display = "none";

        const view = new _cm.EditorView({state: state, parent: container});

        // Inline error banner shown below the editor on client-side validation failure.
        const errorBanner = document.createElement("div");
        errorBanner.className = "alert alert-danger mt-1";
        errorBanner.style.display = "none";
        container.after(errorBanner);

        const denyKeywords = [
            "INSERT", "UPDATE", "DELETE", "DROP", "TRUNCATE", "ALTER", "CREATE",
            "GRANT", "REVOKE", "REPLACE", "CALL", "LOAD", "HANDLER", "LOCK",
            "UNLOCK", "RENAME", "COMMIT", "ROLLBACK", "SAVEPOINT", "USE",
            "EXEC", "EXECUTE", "INTO", "OUTFILE", "COPY", "VACUUM", "MERGE",
        ];

        /**
         * Remove SQL comments and string literals so keyword scanning cannot be fooled
         * by embedded denylist words inside quoted values or comment blocks.
         *
         * @param {string} sql - Raw SQL text.
         * @returns {string} SQL with comments replaced by spaces and string contents blanked.
         */
        function stripCommentsAndStrings(sql) {
            sql = sql.replace(/\/\*[\s\S]*?\*\//g, " ");
            sql = sql.replace(/--[^\n]*/g, " ");
            sql = sql.replace(/#[^\n]*/g, " ");
            sql = sql.replace(/'(?:[^']|'')*'/g, "''");
            sql = sql.replace(/"(?:[^"]|"")*"/g, '""');
            return sql;
        }

        /**
         * Client-side static SQL validation — mirrors the server-side denylist in
         * classes/local/sql/validator.php so obvious errors surface instantly without
         * a round-trip.
         *
         * @param {string} sql - SQL text from the editor.
         * @returns {string|null} Error message string, or null if validation passes.
         */
        function validateSql(sql) {
            sql = sql.trim();
            if (!sql) {
                return "SQL is required.";
            }
            const stripped = stripCommentsAndStrings(sql);
            if (stripped.replace(/;[\s]*$/, "").includes(";")) {
                return "Only a single statement is allowed (no semicolons).";
            }
            if (!/^\s*(SELECT|WITH)\b/i.test(stripped)) {
                return "Query must start with SELECT or WITH.";
            }
            for (const kw of denyKeywords) {
                if (new RegExp("\\b" + kw + "\\b", "i").test(stripped)) {
                    return "Keyword not allowed: " + kw + ".";
                }
            }
            return null;
        }

        let serverValidated = false;

        if (textarea.form) {
            textarea.form.addEventListener("submit", (e) => {
                // Cancel must always return to the index page, even with empty/invalid SQL.
                if (e.submitter && e.submitter.name === "cancel") {
                    return;
                }

                textarea.value = view.state.doc.toString();

                // Static check first — instant, no round-trip.
                const staticErr = validateSql(textarea.value);
                if (staticErr) {
                    e.preventDefault();
                    errorBanner.className = "alert alert-danger mt-1";
                    errorBanner.textContent = staticErr;
                    errorBanner.style.display = "";
                    container.scrollIntoView({behavior: "smooth", block: "nearest"});
                    return;
                }

                // If already server-validated this edit, allow submit through.
                if (serverValidated) {
                    errorBanner.style.display = "none";
                    return;
                }

                e.preventDefault();
                errorBanner.className = "alert alert-info mt-1";
                errorBanner.textContent = "Checking query…";
                errorBanner.style.display = "";

                const payload = JSON.stringify([{
                    index: 0,
                    methodname: "local_reportsources_validate_sql",
                    args: {sql: textarea.value},
                }]);

                fetch(M.cfg.wwwroot + "/lib/ajax/service.php?sesskey=" + M.cfg.sesskey, {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: payload,
                })
                .then(r => r.json())
                .then(data => {
                    const result = Array.isArray(data) ? data[0] : data;
                    if (result.error) {
                        errorBanner.className = "alert alert-danger mt-1";
                        errorBanner.textContent = result.error.message || JSON.stringify(result.error);
                        errorBanner.style.display = "";
                    } else if (result.data && !result.data.ok) {
                        errorBanner.className = "alert alert-danger mt-1";
                        errorBanner.textContent = result.data.error || "Query validation failed.";
                        errorBanner.style.display = "";
                    } else {
                        serverValidated = true;
                        errorBanner.style.display = "none";
                        textarea.form.requestSubmit();
                    }
                })
                .catch(() => {
                    // Network failure — let form submit anyway; server will validate.
                    serverValidated = true;
                    textarea.form.requestSubmit();
                });
            });

            view.dom.addEventListener("keydown", () => {
                serverValidated = false;
                errorBanner.style.display = "none";
            });
        }
    };
}));
