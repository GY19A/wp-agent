/**
 * WP Agent — Full-page Chat
 *
 * Vanilla-JS chat application backed by the poll-driven async run queue.
 * Sends a message via POST /chat/send, then polls GET /chat/poll until the
 * run is done, rendering messages incrementally. No external dependencies.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.wpAgentChat || {};

        // --- DOM references ---
        var convList = document.getElementById('wpa-conv-list');
        var newChatBtn = document.getElementById('wpa-new-chat');
        var thread = document.getElementById('wpa-thread');
        var input = document.getElementById('wpa-input');
        var sendBtn = document.getElementById('wpa-send');
        var stopBtn = document.getElementById('wpa-stop');
        var attachBtn = document.getElementById('wpa-attach');
        var fileInput = document.getElementById('wpa-file-input');
        var attachmentsEl = document.getElementById('wpa-attachments');
        var statusEl = document.getElementById('wpa-status');
        var historyBtn = document.getElementById('wpa-chat-history');
        var historyModal = document.getElementById('wpa-history-modal');
        var historyList = document.getElementById('wpa-history-list');
        var historySearch = document.getElementById('wpa-history-search');

        // --- State ---
        var currentConversationId = null;
        var after = 0;
        var pollingRuns = {};
        var activeRunIds = {};
        var runStatuses = {};
        var activeRunId = null;
        var posting = false;
        var didRestoreConversation = false;
        var pendingAttachments = [];
        var uploading = false;
        var conversationItems = [];
        var historyFilter = '';

        if (!cfg.restUrl || !cfg.nonce) {
            setStatus('Chat is not configured. Reload the page or check the plugin settings.', true);
            return;
        }

        var restUrl = cfg.restUrl.replace(/\/?$/, '/');

        // ----------------------------------------------------------------
        // Networking helpers
        // ----------------------------------------------------------------

        function apiGet(path) {
            // When restUrl already carries a query string (the plain-permalink
            // "/index.php?rest_route=..." form), the path's own "?" must become
            // "&" — otherwise a second "?" folds the query into the route name
            // and the request 404s. Pretty permalinks (no "?" in restUrl) keep
            // the path unchanged.
            var url = ( restUrl.indexOf('?') !== -1 ) ? ( restUrl + path.replace('?', '&') ) : ( restUrl + path );
            return fetch(url, {
                method: 'GET',
                headers: { 'X-WP-Nonce': cfg.nonce },
            }).then(parseResponse);
        }

        function apiPost(path, body) {
            return fetch(restUrl + path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce,
                },
                body: JSON.stringify(body),
            }).then(parseResponse);
        }

        function apiUpload(file) {
            var form = new FormData();
            form.append('file', file);
            return fetch(restUrl + 'chat/upload', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce },
                body: form,
            }).then(parseResponse);
        }

        function parseResponse(response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    var msg = (data && (data.message || data.error)) || ('HTTP ' + response.status);
                    throw new Error(msg);
                }
                return data;
            }, function () {
                throw new Error('HTTP ' + response.status);
            });
        }

        // ----------------------------------------------------------------
        // Status / thinking indicator
        // ----------------------------------------------------------------

        function setStatus(text, isError) {
            if (!statusEl) return;
            statusEl.textContent = '';
            statusEl.classList.remove('wpa-status--error', 'wpa-status--thinking');
            if (!text) return;
            if (isError) {
                statusEl.classList.add('wpa-status--error');
                statusEl.textContent = text;
            } else {
                statusEl.textContent = text;
            }
        }

        function setThinking() {
            setQueueStatus('running');
        }

        function runStatusLabel(status) {
            status = String(status || 'running');
            if (status === 'queued') return 'Queued in background';
            if (status === 'awaiting_confirmation') return 'Awaiting approval';
            if (status === 'canceled') return 'Stopped';
            if (status === 'error') return 'Needs attention';
            if (status === 'done') return 'Completed';
            return 'Agent working';
        }

        function formatQueueStatus(status, queue) {
            var parts = [runStatusLabel(status)];
            var position = queue && parseInt(queue.position, 10);
            var total = queue && parseInt(queue.active_total, 10);
            var localTotal = activeRunCount();

            if (position > 0 && total > 0) {
                parts.push('position ' + position + ' of ' + total);
            } else if (localTotal > 1) {
                parts.push(localTotal + ' active runs');
            }

            if (status === 'queued') {
                parts.push('Composer remains available');
            }

            if (isInterruptibleStatus(status) && hasActiveRuns()) {
                parts.push('Stop available');
            }

            return parts.join(' · ');
        }

        function isInterruptibleStatus(status) {
            status = String(status || 'running');
            return status === 'queued' || status === 'running' || status === 'awaiting_confirmation';
        }

        function setQueueStatus(status, queue) {
            if (!statusEl) return;
            var text = formatQueueStatus(status, queue);
            statusEl.textContent = '';
            statusEl.classList.remove('wpa-status--error', 'wpa-status--thinking');
            if (!text) return;

            var label = document.createElement('span');
            label.className = 'wpa-thinking-label';
            label.textContent = text;
            statusEl.appendChild(label);

            if (status !== 'awaiting_confirmation') {
                statusEl.classList.add('wpa-status--thinking');
                var dots = document.createElement('span');
                dots.className = 'wpa-thinking-dots';
                for (var i = 0; i < 3; i++) {
                    dots.appendChild(document.createElement('span'));
                }
                statusEl.appendChild(dots);
            }
        }

        function clearStatus() {
            setStatus('');
        }

        // ----------------------------------------------------------------
        // Conversation list (left rail)
        // ----------------------------------------------------------------

        function loadConversations() {
            return apiGet('conversations').then(function (data) {
                conversationItems = (data && data.conversations) || [];
                renderConversations(conversationItems);
                renderHistory(conversationItems);
            }).catch(function (err) {
                setStatus('Could not load conversations: ' + err.message, true);
            });
        }

        function renderConversations(items) {
            convList.innerHTML = '';
            if (!items.length) {
                var empty = document.createElement('li');
                empty.className = 'wpa-conv-empty';
                empty.textContent = 'No conversations yet.';
                convList.appendChild(empty);
                return;
            }

            items.forEach(function (conv) {
                var id = parseInt(conv.id, 10);
                var li = document.createElement('li');
                li.className = 'wpa-conv-item';
                li.setAttribute('data-id', String(id));
                li.setAttribute('role', 'button');
                li.setAttribute('tabindex', '0');
                if (id === currentConversationId) {
                    li.classList.add('is-active');
                }

                var title = document.createElement('span');
                title.className = 'wpa-conv-title';
                var first = (conv.first_message || '').trim();
                title.textContent = first ? trim(first, 60) : 'New conversation';

                var meta = document.createElement('span');
                meta.className = 'wpa-conv-meta';
                var count = parseInt(conv.message_count, 10) || 0;
                meta.textContent = count + (count === 1 ? ' message' : ' messages');

                li.appendChild(title);
                li.appendChild(meta);

                li.addEventListener('click', function () {
                    openConversation(id);
                });
                li.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openConversation(id);
                    }
                });

                convList.appendChild(li);
            });

            restoreConversation(items);
        }

        function renderHistory(items) {
            if (!historyList) return;

            var filtered = items.filter(function (conv) {
                if (!historyFilter) return true;
                var haystack = [
                    conv.first_message || '',
                    conv.channel || '',
                    conv.updated_at || '',
                ].join(' ').toLowerCase();
                return haystack.indexOf(historyFilter) !== -1;
            });

            historyList.innerHTML = '';
            if (!filtered.length) {
                var searching = !!(historySearch && historySearch.value.trim());
                var empty = document.createElement('div');
                empty.className = 'wpa-history-empty';
                empty.textContent = searching ? 'No conversations match your search.' : 'No conversations yet.';
                historyList.appendChild(empty);
                return;
            }

            var stack = document.createElement('div');
            stack.className = 'wpa-history-stack';

            filtered.forEach(function (conv) {
                var id = parseInt(conv.id, 10);
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'wpa-history-row';
                button.setAttribute('data-id', String(id));

                var content = document.createElement('span');
                content.className = 'wpa-history-content';

                var title = document.createElement('span');
                title.className = 'wpa-history-first';
                title.textContent = conv.first_message ? trim(String(conv.first_message), 120) : 'New conversation';
                content.appendChild(title);

                // When this row matched on conversation text (deep search),
                // show a short snippet so the user sees why it matched.
                if (conv.match_snippet) {
                    var snippet = document.createElement('span');
                    snippet.className = 'wpa-history-snippet';
                    snippet.textContent = trim(String(conv.match_snippet), 150);
                    content.appendChild(snippet);
                }

                var meta = document.createElement('span');
                meta.className = 'wpa-history-meta';

                var count = document.createElement('span');
                var n = parseInt(conv.message_count, 10) || 0;
                count.textContent = n + (n === 1 ? ' message' : ' messages');

                var when = document.createElement('span');
                when.className = 'wpa-history-time';
                when.textContent = friendlyTime(conv.updated_at);

                meta.appendChild(count);
                if (when.textContent) {
                    var dot = document.createElement('span');
                    dot.className = 'wpa-history-dot';
                    dot.setAttribute('aria-hidden', 'true');
                    dot.textContent = '·';
                    meta.appendChild(dot);
                    meta.appendChild(when);
                }
                content.appendChild(meta);
                button.appendChild(content);
                button.addEventListener('click', function () {
                    closeHistory();
                    openConversation(id);
                });

                stack.appendChild(button);
            });

            historyList.appendChild(stack);
        }

        function openHistory() {
            if (!historyModal) return;
            historyFilter = '';
            if (historySearch) historySearch.value = '';
            renderHistory(conversationItems);
            historyModal.hidden = false;
            historyModal.classList.add('is-open');
            if (historySearch) historySearch.focus();
        }

        function closeHistory() {
            if (!historyModal) return;
            historyModal.classList.remove('is-open');
            historyModal.hidden = true;
            if (historyBtn) historyBtn.focus();
        }

        // Debounced deep search: queries the server so the term can match text
        // produced *inside* conversations, not only their first message. The
        // server returns a `match_snippet` for context. Falls back to showing
        // the full loaded list when the field is cleared.
        var historySearchTimer = null;
        var historySearchSeq = 0;
        function scheduleHistorySearch(term) {
            term = (term || '').trim();
            if (historySearchTimer) window.clearTimeout(historySearchTimer);
            if (!term) {
                renderHistory(conversationItems);
                return;
            }
            historySearchTimer = window.setTimeout(function () {
                var seq = ++historySearchSeq;
                apiGet('conversations?search=' + encodeURIComponent(term)).then(function (data) {
                    if (seq !== historySearchSeq) return; // a newer search superseded this one
                    if (historySearch && historySearch.value.trim() !== term) return;
                    var items = (data && data.conversations) || [];
                    // Server search already filtered; render without re-filtering.
                    historyFilter = '';
                    renderHistory(items);
                }).catch(function () {
                    // On failure, keep the instant local filter result.
                });
            }, 220);
        }

        // Turn a stored datetime ("YYYY-MM-DD HH:MM:SS", UTC) into a friendly
        // relative label like "5 minutes ago" / "yesterday".
        function friendlyTime(value) {
            if (!value) return '';
            var iso = String(value).replace(' ', 'T');
            if (!/[zZ]|[+\-]\d\d:?\d\d$/.test(iso)) iso += 'Z';
            var then = new Date(iso);
            if (isNaN(then.getTime())) return String(value);
            var diff = Math.floor((Date.now() - then.getTime()) / 1000);
            if (diff < 45) return 'just now';
            if (diff < 90) return 'a minute ago';
            if (diff < 3600) return Math.round(diff / 60) + ' minutes ago';
            if (diff < 5400) return 'an hour ago';
            if (diff < 86400) return Math.round(diff / 3600) + ' hours ago';
            if (diff < 172800) return 'yesterday';
            if (diff < 604800) return Math.round(diff / 86400) + ' days ago';
            return then.toLocaleDateString();
        }

        function restoreConversation(items) {
            if (didRestoreConversation || currentConversationId || !items.length) return;
            didRestoreConversation = true;

            var savedId = parseInt(localStorage.getItem('wp_agent_current_conversation') || '0', 10);
            var target = null;

            if (savedId) {
                target = items.find(function (conv) {
                    return parseInt(conv.id, 10) === savedId;
                });
            }

            if (!target) {
                target = items[0];
            }

            if (target && target.id) {
                openConversation(parseInt(target.id, 10));
            }
        }

        function markActiveConversation(id) {
            var nodes = convList.querySelectorAll('.wpa-conv-item');
            for (var i = 0; i < nodes.length; i++) {
                var nodeId = parseInt(nodes[i].getAttribute('data-id'), 10);
                if (nodeId === id) {
                    nodes[i].classList.add('is-active');
                } else {
                    nodes[i].classList.remove('is-active');
                }
            }
        }

        // ----------------------------------------------------------------
        // Open / create conversations
        // ----------------------------------------------------------------

        function openConversation(id) {
            currentConversationId = id;
            localStorage.setItem('wp_agent_current_conversation', String(id));
            after = 0;
            resetRunTracking();
            thread.innerHTML = '';
            markActiveConversation(id);
            clearStatus();

            // Resume the active run (activity panel + sub-agents) in parallel with
            // loading history, so switching back to a working conversation shows
            // its live activity immediately instead of waiting for history first.
            resumeActiveRun(id);

            apiGet('chat/history?conversation_id=' + encodeURIComponent(id)).then(function (data) {
                if (currentConversationId !== id) return;
                var messages = (data && data.messages) || [];
                messages.forEach(renderMessage);
                advanceAfter(messages);
                scrollToBottom();
            }).catch(function (err) {
                setStatus('Could not load conversation: ' + err.message, true);
            });
        }

        function newChat() {
            currentConversationId = null;
            localStorage.removeItem('wp_agent_current_conversation');
            after = 0;
            resetRunTracking();
            thread.innerHTML = '';
            markActiveConversation(null);
            clearStatus();
            renderEmptyState();
            if (input) {
                input.focus();
            }
        }

        function renderEmptyState() {
            var wrap = document.createElement('div');
            wrap.className = 'wpa-empty-state';

            var badge = document.createElement('div');
            badge.className = 'wpa-empty-badge';
            badge.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.2 5.6L20 11l-5.8 2.4L12 19l-2.2-5.6L4 11l5.8-2.4z"/></svg>';

            var title = document.createElement('div');
            title.className = 'wpa-empty-title';
            title.textContent = cfg.siteName ? ('How can I help with ' + cfg.siteName + '?') : 'How can I help with your site?';

            var sub = document.createElement('div');
            sub.className = 'wpa-empty-sub';
            sub.textContent = 'Ask anything, or start with a quick action below.';

            wrap.appendChild(badge);
            wrap.appendChild(title);
            wrap.appendChild(sub);

            // Quick-action suggestion cards built from the registered slash
            // commands, so new users can discover the agent's headline workflows.
            var commands = (cfg && cfg.slashCommands) || [];
            if (commands.length) {
                var grid = document.createElement('div');
                grid.className = 'wpa-empty-grid';
                commands.slice(0, 6).forEach(function (cmd) {
                    if (!cmd || !cmd.command) return;
                    var card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'wpa-empty-card';
                    var t = document.createElement('span');
                    t.className = 'wpa-empty-card-title';
                    t.textContent = cmd.title || cmd.command;
                    var d = document.createElement('span');
                    d.className = 'wpa-empty-card-desc';
                    d.textContent = cmd.description || '';
                    card.appendChild(t);
                    card.appendChild(d);
                    card.addEventListener('click', function () {
                        if (!input) return;
                        var insert = cmd.command + ' ';
                        input.value = insert;
                        input.focus();
                        // place caret at end
                        try { input.setSelectionRange(insert.length, insert.length); } catch (e) {}
                        // trigger input handlers (enables send button, slash hints)
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                    grid.appendChild(card);
                });
                wrap.appendChild(grid);
            }

            thread.appendChild(wrap);
        }

        function clearEmptyState() {
            var es = thread.querySelector('.wpa-empty-state');
            if (es) {
                es.parentNode.removeChild(es);
            }
        }

        function normalizeRunId(runId) {
            return String(parseInt(runId, 10) || runId);
        }

        function activeRunCount() {
            return Object.keys(activeRunIds).length;
        }

        function hasActiveRuns() {
            return activeRunCount() > 0;
        }

        function setRunActive(runId, isActive, status) {
            var id = normalizeRunId(runId);
            if (!id) return;
            if (status) {
                runStatuses[id] = status;
            }
            if (isActive) {
                activeRunIds[id] = true;
                activeRunId = id;
            } else {
                delete activeRunIds[id];
                delete pollingRuns[id];
                if (activeRunId === id) {
                    activeRunId = selectNewestActiveRunId();
                }
            }
            updateStopButton();
        }

        function selectNewestActiveRunId() {
            var ids = Object.keys(activeRunIds);
            if (!ids.length) return null;
            ids.sort(function (a, b) {
                return (parseInt(a, 10) || 0) - (parseInt(b, 10) || 0);
            });
            return ids[ids.length - 1];
        }

        function selectCancelableRunId() {
            var ids = Object.keys(activeRunIds);
            if (!ids.length) return null;
            ids.sort(function (a, b) {
                return (parseInt(a, 10) || 0) - (parseInt(b, 10) || 0);
            });
            for (var i = 0; i < ids.length; i++) {
                if (runStatuses[ids[i]] === 'running' || runStatuses[ids[i]] === 'awaiting_confirmation') {
                    return ids[i];
                }
            }
            return ids[0];
        }

        function resetRunTracking() {
            pollingRuns = {};
            activeRunIds = {};
            runStatuses = {};
            activeRunId = null;
            updateStopButton();
        }

        // ----------------------------------------------------------------
        // Sending + polling
        // ----------------------------------------------------------------

        function send() {
            var text = input.value.trim();
            if ((!text && !pendingAttachments.length) || posting || uploading) return;

            setSendDisabled(true);

            var attachments = pendingAttachments.slice();
            var payload = {
                message: text,
                attachments: attachments.map(function (item) {
                    return { id: item.id };
                })
            };
            if (currentConversationId) {
                payload.conversation_id = currentConversationId;
            }

            apiPost('chat/send', payload).then(function (resp) {
                currentConversationId = resp.conversation_id;
                localStorage.setItem('wp_agent_current_conversation', String(currentConversationId));
                markActiveConversation(currentConversationId);
                clearEmptyState();
                renderMessage(resp.message);
                after = Math.max(after, parseInt(resp.message.id, 10) || 0);
                input.value = '';
                pendingAttachments = [];
                renderAttachments();
                autoGrow();
                scrollToBottom();
                setRunActive(resp.run_id, true, 'queued');
                setQueueStatus('queued', resp.queue);
                poll(resp.run_id, resp.queue);
            }).catch(function (err) {
                setStatus('Failed to send: ' + err.message, true);
            }).finally(function () {
                setSendDisabled(false);
            });
        }

        function poll(runId, initialQueue) {
            if (!runId) return;
            var normalizedRunId = normalizeRunId(runId);
            if (pollingRuns[normalizedRunId]) return;
            var conversationForRun = currentConversationId;
            pollingRuns[normalizedRunId] = true;
            setRunActive(normalizedRunId, true, runStatuses[normalizedRunId] || 'queued');
            setQueueStatus(runStatuses[normalizedRunId] || 'queued', initialQueue);

            var tick = function () {
                if (currentConversationId !== conversationForRun) {
                    setRunActive(normalizedRunId, false);
                    if (!hasActiveRuns()) clearStatus();
                    return;
                }

                var url = 'chat/poll?run_id=' + encodeURIComponent(runId) +
                    '&conversation_id=' + encodeURIComponent(conversationForRun) +
                    '&after=' + encodeURIComponent(after);

                apiGet(url).then(function (data) {
                    runStatuses[normalizedRunId] = data.status || runStatuses[normalizedRunId] || 'running';
                    var messages = (data && data.messages) || [];
                    messages.forEach(renderMessage);
                    advanceAfter(messages);
                    scrollToBottom();

                    if (data.needs_confirmation && data.confirmation) {
                        pollingRuns[normalizedRunId] = false;
                        setRunActive(normalizedRunId, true, 'awaiting_confirmation');
                        setQueueStatus('awaiting_confirmation', data.queue);
                        renderConfirmation(data.confirmation, runId);
                        return;
                    }

                    if (data.done) {
                        setRunActive(normalizedRunId, false, data.status || 'done');
                        if (data.status === 'canceled') {
                            setStatus(formatStoppedStatus(data.queue));
                        } else if (data.error) {
                            setStatus(data.error, true);
                        } else if (hasActiveRuns()) {
                            setThinking();
                        } else {
                            clearStatus();
                        }
                        loadConversations();
                        return;
                    }

                    setQueueStatus(data.status || 'running', data.queue);
                    window.setTimeout(tick, 1100);
                }).catch(function (err) {
                    setRunActive(normalizedRunId, false);
                    setStatus('Lost connection: ' + err.message, true);
                });
            };

            window.setTimeout(tick, 1100);
        }

        function cancelActiveRun() {
            var runId = selectCancelableRunId();
            if (!runId) return;

            if (stopBtn) stopBtn.disabled = true;
            setStatus('Stopping agent run...');

            apiPost('chat/runs/' + encodeURIComponent(runId) + '/cancel', {
                conversation_id: currentConversationId
            }).then(function (data) {
                runStatuses[runId] = (data && data.status) || 'canceled';
                setRunActive(runId, false, runStatuses[runId]);
                setStatus(formatStoppedStatus(data && data.queue));
                loadConversations();
            }).catch(function (err) {
                setStatus('Could not stop: ' + err.message, true);
            }).finally(function () {
                updateStopButton();
            });
        }

        function formatStoppedStatus(queue) {
            var remaining = queue && parseInt(queue.active_total, 10);
            if (!remaining) {
                remaining = activeRunCount();
            }
            return remaining > 0
                ? 'Stopped current run. Remaining queued work will continue. ' + remaining + ' active.'
                : 'Stopped.';
        }

        function renderConfirmation(confirmation, runId) {
            var existing = document.querySelector('.wpa-confirmation[data-id="' + confirmation.id + '"]');
            if (existing) return;

            var wrap = document.createElement('div');
            wrap.className = 'wpa-confirmation';
            wrap.setAttribute('data-id', String(confirmation.id));

            var title = document.createElement('div');
            title.className = 'wpa-confirmation-title';
            title.textContent = 'Approval required';

            var body = document.createElement('div');
            body.className = 'wpa-confirmation-body';
            body.textContent = (confirmation.tool || 'tool') + (confirmation.action ? ' / ' + confirmation.action : '');

            var actions = document.createElement('div');
            actions.className = 'wpa-confirmation-actions';

            var approve = document.createElement('button');
            approve.type = 'button';
            approve.className = 'wpa-confirmation-approve';
            approve.textContent = 'Approve';

            var reject = document.createElement('button');
            reject.type = 'button';
            reject.className = 'wpa-confirmation-reject';
            reject.textContent = 'Reject';

            approve.addEventListener('click', function () {
                decideConfirmation(confirmation.id, 'approve', wrap, runId);
            });
            reject.addEventListener('click', function () {
                decideConfirmation(confirmation.id, 'reject', wrap, runId);
            });

            actions.appendChild(approve);
            actions.appendChild(reject);
            wrap.appendChild(title);
            wrap.appendChild(body);
            wrap.appendChild(actions);
            thread.appendChild(wrap);
            scrollToBottom();
        }

        function decideConfirmation(id, decision, node, runId) {
            var buttons = node.querySelectorAll('button');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].disabled = true;
            }
            apiPost('confirmations/' + encodeURIComponent(id) + '/' + decision, {}).then(function () {
                node.classList.add('is-decided');
                setStatus(decision === 'approve' ? 'Approved. Resuming run...' : 'Rejected. Resuming run...');
                poll(runId);
            }).catch(function (err) {
                setStatus('Could not ' + decision + ': ' + err.message, true);
                for (var j = 0; j < buttons.length; j++) {
                    buttons[j].disabled = false;
                }
            });
        }

        function resumeActiveRun(conversationId) {
            if (!conversationId) return;

            apiGet('chat/active-run?conversation_id=' + encodeURIComponent(conversationId)).then(function (data) {
                var run = data && data.run;
                if (!run || !run.id || currentConversationId !== conversationId) {
                    return;
                }
                setRunActive(run.id, true, run.status || 'queued');
                setQueueStatus(run.status || 'queued', data.queue);
                poll(run.id, data.queue);
            }).catch(function (err) {
                setStatus('Could not resume the active agent session: ' + err.message, true);
            });
        }

        function advanceAfter(messages) {
            for (var i = 0; i < messages.length; i++) {
                var id = parseInt(messages[i].id, 10) || 0;
                if (id > after) {
                    after = id;
                }
            }
        }

        // ----------------------------------------------------------------
        // Message rendering
        // ----------------------------------------------------------------

        function renderMessage(m) {
            if (!m || !m.role) return;
            if (m.role === 'system') return;

            // Tool RESULT messages carry no tool name (it lives on the assistant
            // turn that requested them) — we surface tools as chips on the
            // assistant message instead, so skip the raw tool results here.
            if (m.role === 'tool') return;

            if (m.role === 'assistant') {
                // An assistant turn that only made tool calls has empty prose:
                // render "used <tool>" chips and skip the empty bubble.
                var calls = m.tool_calls;
                if (typeof calls === 'string') {
                    try { calls = JSON.parse(calls); } catch (e) { calls = null; }
                }
                if (calls && calls.length) {
                    renderToolChips(calls);
                }
                var content = (m.content == null) ? '' : String(m.content);
                if (content.trim() === '') return;

                var arow = document.createElement('div');
                arow.className = 'wpa-msg wpa-msg--assistant';
                var aavatar = document.createElement('div');
                aavatar.className = 'wpa-msg-avatar';
                aavatar.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.2 5.6L20 11l-5.8 2.4L12 19l-2.2-5.6L4 11l5.8-2.4z"/></svg>';
                var abubble = document.createElement('div');
                abubble.className = 'wpa-bubble';
                abubble.innerHTML = renderMarkdown(content);
                arow.appendChild(aavatar);
                arow.appendChild(abubble);
                thread.appendChild(arow);
                return;
            }

            // User message: render the same safe markdown subset as assistant
            // replies, while preserving the special attachment summary block.
            var urow = document.createElement('div');
            urow.className = 'wpa-msg wpa-msg--user';
            var ububble = document.createElement('div');
            ububble.className = 'wpa-bubble';
            ububble.innerHTML = renderUserContent(m.content || '');
            urow.appendChild(ububble);
            thread.appendChild(urow);
        }

        function renderUserContent(content) {
            var text = String(content || '');
            var parts = text.split('\nAttached media:\n');
            var html = renderMarkdown(parts[0] || '');
            if (parts[1]) {
                html += '<div class="wpa-message-attachments">';
                var lines = parts[1].split('\n');
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].replace(/^[-\s]+/, '').trim();
                    if (!line) continue;
                    html += '<div class="wpa-message-attachment">' + linkifyAttachmentLine(line) + '</div>';
                }
                html += '</div>';
            }
            return html;
        }

        function linkifyAttachmentLine(line) {
            var escaped = escapeHtml(line);
            return escaped.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
        }

        // Map a tool name to a small inline SVG icon and a human-readable label
        // so the activity trail reads like a clear sequence of actions rather
        // than raw tool identifiers.
        var TOOL_META = {
            manage_posts:    { icon: 'doc',     label: 'Wrote a post' },
            manage_pages:    { icon: 'doc',     label: 'Edited a page' },
            manage_media:    { icon: 'image',   label: 'Managed media' },
            generate_image:  { icon: 'image',   label: 'Generated an image' },
            manage_seo:      { icon: 'search',  label: 'Saved SEO metadata' },
            manage_taxonomies:{ icon: 'tag',    label: 'Updated categories & tags' },
            manage_menus:    { icon: 'list',    label: 'Updated menus' },
            manage_comments: { icon: 'chat',    label: 'Handled comments' },
            manage_users:    { icon: 'user',    label: 'Managed users' },
            content_quality: { icon: 'check',   label: 'Ran a quality check' },
            web:             { icon: 'globe',   label: 'Searched the web' },
            'web.search':    { icon: 'globe',   label: 'Searched the web' },
            'web.fetch':     { icon: 'globe',   label: 'Fetched a page' },
            manage_schedules:{ icon: 'clock',   label: 'Updated a schedule' },
            request_approval:{ icon: 'shield',  label: 'Requested approval' },
            journal:         { icon: 'book',    label: 'Updated the journal' },
            manage_skills:   { icon: 'spark',   label: 'Worked with skills' },
            manage_files:    { icon: 'folder',  label: 'Worked with files' },
            get_site_info:   { icon: 'info',    label: 'Read site info' },
            plan:            { icon: 'list',    label: 'Planned the work' },
            delegate:        { icon: 'spark',   label: 'Delegated sub-tasks' },
            runtime:         { icon: 'gear',    label: 'Used runtime' },
            execute_code:    { icon: 'code',    label: 'Ran code' },
            manage_woocommerce: { icon: 'cart', label: 'Managed WooCommerce' }
        };

        var TOOL_ICON_PATHS = {
            doc:    '<path d="M4 1.5h5L12.5 5v9.5H4z" fill="none"/><path d="M9 1.5V5h3.5"/><path d="M6 8h4M6 10.5h4"/>',
            image:  '<rect x="2.5" y="3" width="11" height="10" rx="1.5"/><circle cx="6" cy="6.5" r="1.2"/><path d="M3.5 11l3-2.5 2.5 2 1.5-1.2 2.5 2"/>',
            search: '<circle cx="7" cy="7" r="4"/><path d="M10 10l3 3"/>',
            globe:  '<circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2.5 2 9.5 0 12M8 2c-2 2.5-2 9.5 0 12"/>',
            tag:    '<path d="M8.5 2.5H3v5.5L9.5 14l4-4z"/><circle cx="5.5" cy="5.5" r="0.8" fill="currentColor"/>',
            list:   '<path d="M3 4h10M3 8h10M3 12h10"/>',
            chat:   '<path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z"/>',
            user:   '<circle cx="8" cy="5.5" r="2.5"/><path d="M3.5 13.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/>',
            check:  '<circle cx="8" cy="8" r="6"/><path d="M5.5 8.2l1.8 1.8 3.2-3.6"/>',
            clock:  '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 1.5"/>',
            shield: '<path d="M8 2l5 2v4c0 3-2.2 5-5 6-2.8-1-5-3-5-6V4z"/><path d="M5.8 8l1.6 1.6 3-3.2"/>',
            book:   '<path d="M3 3h4.5c1 0 1.5.5 1.5 1.5V13c0-1-.5-1.5-1.5-1.5H3z"/><path d="M13 3H8.5C7.5 3 7 3.5 7 4.5V13c0-1 .5-1.5 1.5-1.5H13z"/>',
            spark:  '<path d="M8 2l1.4 3.6L13 7l-3.6 1.4L8 12l-1.4-3.6L3 7l3.6-1.4z"/>',
            folder: '<path d="M2.5 4h3.5l1.2 1.5h6.3V12h-11z"/>',
            info:   '<circle cx="8" cy="8" r="6"/><path d="M8 7.5V11M8 5.2v.2"/>',
            gear:   '<circle cx="8" cy="8" r="2"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.5 3.5l1.5 1.5M11 11l1.5 1.5M12.5 3.5L11 5M5 11l-1.5 1.5"/>',
            code:   '<path d="M5.5 5L3 8l2.5 3M10.5 5L13 8l-2.5 3M9 4l-2 8"/>',
            cart:   '<path d="M2 2.5h2l1.5 7.5h6l1.5-5H5"/><circle cx="6.5" cy="13" r="0.9" fill="currentColor"/><circle cx="11.5" cy="13" r="0.9" fill="currentColor"/>'
        };

        function toolIconSvg(kind) {
            var paths = TOOL_ICON_PATHS[kind] || TOOL_ICON_PATHS.gear;
            return '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
        }

        function renderToolChips(calls) {
            for (var i = 0; i < calls.length; i++) {
                var tc = calls[i];
                var name = (tc && tc.function && tc.function.name) || (tc && tc.name) || 'tool';
                var meta = TOOL_META[name] || { icon: 'gear', label: 'Used ' + name };

                var chip = document.createElement('div');
                chip.className = 'wpa-tool-chip';
                chip.title = name;

                var icon = document.createElement('span');
                icon.className = 'wpa-tool-icon';
                icon.innerHTML = toolIconSvg(meta.icon);

                var label = document.createElement('span');
                label.className = 'wpa-tool-name';
                label.textContent = meta.label;

                chip.appendChild(icon);
                chip.appendChild(label);
                thread.appendChild(chip);
            }
        }

        function extractToolNames(m) {
            var names = [];

            // Prefer structured tool_calls when present.
            var calls = m.tool_calls;
            if (typeof calls === 'string') {
                try {
                    calls = JSON.parse(calls);
                } catch (e) {
                    calls = null;
                }
            }
            if (calls && calls.length) {
                for (var i = 0; i < calls.length; i++) {
                    var tc = calls[i];
                    var n = (tc && tc.function && tc.function.name) || (tc && tc.name);
                    if (n) {
                        names.push(String(n));
                    }
                }
            }

            // Fall back to a name field embedded in the content JSON.
            if (!names.length && m.content) {
                try {
                    var parsed = JSON.parse(m.content);
                    if (parsed && parsed.name) {
                        names.push(String(parsed.name));
                    } else if (parsed && parsed.tool) {
                        names.push(String(parsed.tool));
                    }
                } catch (e) { /* not JSON — leave empty */ }
            }

            return names;
        }

        // ----------------------------------------------------------------
        // Minimal, XSS-safe markdown
        // ----------------------------------------------------------------

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        /**
         * Render a safe subset of markdown. Raw server text is escaped before
         * generated markup is assembled, so only tags created here reach the UI.
         */
        function renderMarkdown(raw) {
            var source = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            var blocks = [];
            var tokenPrefix = '\u0000WPA_BLOCK_';

            source = source.replace(/```([^\n`]*)\n?([\s\S]*?)```/g, function (match, lang, code) {
                var idx = blocks.length;
                var language = String(lang || '').trim().replace(/[^a-z0-9_-]/gi, '').toLowerCase();
                var className = language ? ' class="language-' + language + '"' : '';
                blocks.push('<pre class="wpa-code"><code' + className + '>' + escapeHtml(code.replace(/^\n/, '').replace(/\n$/, '')) + '</code></pre>');
                return tokenPrefix + idx + '\u0000';
            });

            var lines = source.split('\n');
            var html = [];
            var paragraph = [];
            var listType = null;
            var listItems = [];

            function closeParagraph() {
                if (!paragraph.length) return;
                html.push('<p>' + inline(paragraph.join('\n')).replace(/\n/g, '<br>') + '</p>');
                paragraph = [];
            }

            function closeList() {
                if (!listType) return;
                html.push('<' + listType + ' class="wpa-list"><li>' + listItems.join('</li><li>') + '</li></' + listType + '>');
                listType = null;
                listItems = [];
            }

            function isTableStart(index) {
                if (index + 1 >= lines.length) return false;
                return /^\s*\|.+\|\s*$/.test(lines[index]) && /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(lines[index + 1]);
            }

            function parseTable(index) {
                var header = splitTableRow(lines[index]);
                var align = splitTableRow(lines[index + 1]).map(function (cell) {
                    cell = cell.trim();
                    if (/^:-+:$/.test(cell)) return 'center';
                    if (/^-+:$/.test(cell)) return 'right';
                    return 'left';
                });
                var body = [];
                var cursor = index + 2;
                while (cursor < lines.length && /^\s*\|.+\|\s*$/.test(lines[cursor])) {
                    body.push(splitTableRow(lines[cursor]));
                    cursor++;
                }

                var table = '<div class="wpa-table-wrap"><table class="wpa-md-table"><thead><tr>';
                for (var h = 0; h < header.length; h++) {
                    table += '<th style="text-align:' + (align[h] || 'left') + '">' + inline(header[h].trim()) + '</th>';
                }
                table += '</tr></thead><tbody>';
                for (var r = 0; r < body.length; r++) {
                    table += '<tr>';
                    for (var c = 0; c < header.length; c++) {
                        table += '<td style="text-align:' + (align[c] || 'left') + '">' + inline((body[r][c] || '').trim()) + '</td>';
                    }
                    table += '</tr>';
                }
                table += '</tbody></table></div>';
                html.push(table);
                return cursor - 1;
            }

            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                var trimmed = line.trim();

                if (/^\u0000WPA_BLOCK_\d+\u0000$/.test(trimmed)) {
                    closeParagraph();
                    closeList();
                    html.push(trimmed);
                    continue;
                }

                if (trimmed === '') {
                    closeParagraph();
                    closeList();
                    continue;
                }

                if (isTableStart(i)) {
                    closeParagraph();
                    closeList();
                    i = parseTable(i);
                    continue;
                }

                if (/^---+$/.test(trimmed) || /^\*\*\*+$/.test(trimmed)) {
                    closeParagraph();
                    closeList();
                    html.push('<hr class="wpa-md-hr">');
                    continue;
                }

                var heading = line.match(/^\s{0,3}(#{1,6})\s+(.+)$/);
                if (heading) {
                    closeParagraph();
                    closeList();
                    var level = Math.min(heading[1].length, 4);
                    html.push('<h' + level + ' class="wpa-md-heading wpa-md-heading-' + level + '">' + inline(heading[2].trim()) + '</h' + level + '>');
                    continue;
                }

                var quote = line.match(/^\s{0,3}>\s?(.*)$/);
                if (quote) {
                    closeParagraph();
                    closeList();
                    var quoteLines = [ quote[1] ];
                    while (i + 1 < lines.length) {
                        var nextQuote = lines[i + 1].match(/^\s{0,3}>\s?(.*)$/);
                        if (!nextQuote) break;
                        quoteLines.push(nextQuote[1]);
                        i++;
                    }
                    html.push('<blockquote>' + renderMarkdown(quoteLines.join('\n')) + '</blockquote>');
                    continue;
                }

                var unordered = line.match(/^\s*[-*+]\s+(.*)$/);
                var ordered = line.match(/^\s*\d+[.)]\s+(.*)$/);
                if (unordered || ordered) {
                    closeParagraph();
                    var nextType = unordered ? 'ul' : 'ol';
                    if (listType && listType !== nextType) closeList();
                    if (!listType) listType = nextType;
                    listItems.push(inline((unordered || ordered)[1]));
                    continue;
                }

                closeList();
                paragraph.push(line);
            }

            closeParagraph();
            closeList();

            return html.join('').replace(/\u0000WPA_BLOCK_(\d+)\u0000/g, function (match, idx) {
                return blocks[parseInt(idx, 10)] || '';
            });
        }

        function splitTableRow(row) {
            row = row.trim();
            if (row.charAt(0) === '|') row = row.slice(1);
            if (row.charAt(row.length - 1) === '|') row = row.slice(0, -1);
            return row.split('|');
        }

        /**
         * Inline transforms applied to already-escaped text:
         * bold, inline code, and links. URLs are validated to http(s).
         */
        function inline(text) {
            text = escapeHtml(text);
            // Inline code (run before bold so ** inside code is preserved).
            text = text.replace(/`([^`]+)`/g, '<code class="wpa-inline-code">$1</code>');
            // Images: ![alt](url) — rendered as links to avoid loading remote
            // media unexpectedly inside the admin UI.
            text = text.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, function (match, alt, url) {
                if (/^https?:\/\//i.test(url)) {
                    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + (alt || url) + '</a>';
                }
                return match;
            });
            // Bold.
            text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/__([^_]+)__/g, '<strong>$1</strong>');
            // Italic.
            text = text.replace(/(^|\s)\*([^*\n]+)\*/g, '$1<em>$2</em>');
            text = text.replace(/(^|\s)_([^_\n]+)_/g, '$1<em>$2</em>');
            // Strikethrough.
            text = text.replace(/~~([^~]+)~~/g, '<del>$1</del>');
            // Links: [label](url) — only http/https/mailto allowed.
            text = text.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (match, label, url) {
                if (/^https?:\/\//i.test(url) || /^mailto:/i.test(url)) {
                    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
                }
                return match;
            });
            // Bare URLs: linkify any http(s) URL not already inside an anchor.
            // The URL may directly follow punctuation (e.g. a Chinese colon
            // "预览：http://...") so we do NOT require a leading space — instead we
            // skip URLs that are already part of an href="..." attribute. Note the
            // text is HTML-escaped, so "&" appears as "&amp;" inside query strings;
            // keep those intact (a valid href) and only trim trailing sentence
            // punctuation that is not part of an HTML entity.
            text = text.replace(/(href=")?(https?:\/\/[^\s<)"']+)/g, function (match, hrefPrefix, url) {
                if (hrefPrefix) {
                    return match; // already inside a generated anchor — leave it.
                }
                // Trim trailing sentence punctuation (., ! ? and CJK equivalents)
                // so it isn't swallowed into the link. ';' and ':' are excluded to
                // avoid breaking HTML entities like "&amp;".
                var trailing = '';
                var m = url.match(/[.,!?，。！？]+$/);
                if (m) {
                    trailing = m[0];
                    url = url.slice(0, url.length - trailing.length);
                }
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trailing;
            });
            return text;
        }

        // ----------------------------------------------------------------
        // UI utilities
        // ----------------------------------------------------------------

        function setSendDisabled(disabled) {
            posting = !!disabled;
            if (sendBtn) {
                sendBtn.disabled = posting || uploading;
            }
            if (input && !posting) {
                input.focus();
            }
        }

        function setUploading(isUploading) {
            uploading = isUploading;
            if (attachBtn) attachBtn.disabled = isUploading;
            if (sendBtn) sendBtn.disabled = isUploading || posting;
            if (isUploading) {
                setStatus('Uploading media...');
            } else if (!hasActiveRuns()) {
                clearStatus();
            } else {
                setThinking();
            }
        }

        function updateStopButton() {
            if (!stopBtn) return;
            var visible = hasActiveRuns();
            var count = activeRunCount();
            var label = count > 1 ? ('Stop current agent run; queued work continues (' + count + ' active runs)') : 'Stop current agent run; queued work continues';
            stopBtn.hidden = !visible;
            stopBtn.disabled = !visible;
            stopBtn.setAttribute('aria-label', label);
            stopBtn.setAttribute('title', label);
            stopBtn.setAttribute('aria-describedby', 'wpa-status');
        }

        function handleFiles(files) {
            files = Array.prototype.slice.call(files || []);
            if (!files.length) return;
            var remaining = Math.max(0, 8 - pendingAttachments.length);
            files = files.slice(0, remaining);
            if (!files.length) {
                setStatus('You can attach up to 8 files per message.', true);
                return;
            }

            setUploading(true);
            var chain = Promise.resolve();
            files.forEach(function (file) {
                chain = chain.then(function () {
                    return apiUpload(file).then(function (data) {
                        if (data && data.attachment) {
                            pendingAttachments.push(data.attachment);
                            renderAttachments();
                        }
                    });
                });
            });
            chain.then(function () {
                setUploading(false);
                if (input) input.focus();
            }).catch(function (err) {
                setUploading(false);
                setStatus('Upload failed: ' + err.message, true);
            });
        }

        function renderAttachments() {
            if (!attachmentsEl) return;
            attachmentsEl.innerHTML = '';
            if (!pendingAttachments.length) {
                attachmentsEl.classList.remove('has-items');
                return;
            }
            attachmentsEl.classList.add('has-items');
            pendingAttachments.forEach(function (item, index) {
                var chip = document.createElement('div');
                chip.className = 'wpa-attachment-chip';

                var icon = document.createElement('span');
                icon.className = 'wpa-attachment-icon';
                icon.textContent = mediaIcon(item.mime_type || '');

                var meta = document.createElement('span');
                meta.className = 'wpa-attachment-meta';
                meta.textContent = trim(item.filename || ('Attachment #' + item.id), 36);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'wpa-attachment-remove';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove attachment');
                remove.addEventListener('click', function () {
                    pendingAttachments.splice(index, 1);
                    renderAttachments();
                    if (input) input.focus();
                });

                chip.appendChild(icon);
                chip.appendChild(meta);
                chip.appendChild(remove);
                attachmentsEl.appendChild(chip);
            });
        }

        function mediaIcon(mime) {
            if (mime.indexOf('image/') === 0) return 'IMG';
            if (mime.indexOf('audio/') === 0) return 'AUD';
            if (mime.indexOf('video/') === 0) return 'VID';
            if (mime === 'application/pdf') return 'PDF';
            return 'FILE';
        }

        function autoGrow() {
            if (!input) return;
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 200) + 'px';
        }

        // ----------------------------------------------------------------
        // Slash command palette
        // ----------------------------------------------------------------

        var slashCommands = Array.isArray(cfg.slashCommands) ? cfg.slashCommands : [];
        var slashMenu = null;
        var slashItems = [];
        var slashActive = -1;

        function slashOpen() {
            return slashMenu && !slashMenu.hidden;
        }

        function ensureSlashMenu() {
            if (slashMenu || !input || !slashCommands.length) return;
            slashMenu = document.createElement('div');
            slashMenu.id = 'wpa-slash-menu';
            slashMenu.className = 'wpa-slash-menu';
            slashMenu.setAttribute('role', 'listbox');
            slashMenu.setAttribute('aria-label', 'Slash commands');
            slashMenu.hidden = true;
            var composer = input.closest('.wpa-composer') || input.parentNode;
            composer.appendChild(slashMenu);
        }

        function renderSlashMenu(filter) {
            ensureSlashMenu();
            if (!slashMenu) return;
            var q = filter.replace(/^\//, '').toLowerCase();
            var matches = slashCommands.filter(function (cmd) {
                return cmd.command.slice(1).toLowerCase().indexOf(q) === 0 ||
                    (cmd.title || '').toLowerCase().indexOf(q) !== -1;
            });
            slashItems = [];
            slashActive = -1;
            slashMenu.textContent = '';
            if (!matches.length) {
                hideSlashMenu();
                return;
            }
            matches.forEach(function (cmd, idx) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'wpa-slash-item';
                item.setAttribute('role', 'option');
                item.dataset.index = String(idx);

                var name = document.createElement('span');
                name.className = 'wpa-slash-cmd';
                name.textContent = cmd.command + (cmd.hint ? ' <' + cmd.hint + '>' : '');
                item.appendChild(name);

                var desc = document.createElement('span');
                desc.className = 'wpa-slash-desc';
                desc.textContent = cmd.description || cmd.title || '';
                item.appendChild(desc);

                item.addEventListener('mousedown', function (e) {
                    // mousedown so the textarea does not lose focus before we apply.
                    e.preventDefault();
                    applySlashCommand(cmd);
                });
                item.addEventListener('mousemove', function () {
                    setSlashActive(idx);
                });
                slashMenu.appendChild(item);
                slashItems.push({ el: item, cmd: cmd });
            });
            slashMenu.hidden = false;
            setSlashActive(0);
        }

        function setSlashActive(idx) {
            slashActive = idx;
            slashItems.forEach(function (entry, i) {
                if (i === idx) {
                    entry.el.classList.add('is-active');
                    entry.el.setAttribute('aria-selected', 'true');
                } else {
                    entry.el.classList.remove('is-active');
                    entry.el.setAttribute('aria-selected', 'false');
                }
            });
        }

        function hideSlashMenu() {
            if (slashMenu) {
                slashMenu.hidden = true;
                slashMenu.textContent = '';
            }
            slashItems = [];
            slashActive = -1;
        }

        function applySlashCommand(cmd) {
            if (!input) return;
            hideSlashMenu();
            // Insert only the short command token, like Codex/Hermes. The agent
            // backend reads the workflow template; the composer stays clean.
            // Commands that take an argument get a trailing space so the user
            // can immediately type the title/topic.
            var needsArg = cmd.needs === 'text';
            input.value = cmd.command + (needsArg ? ' ' : '');
            input.focus();
            autoGrow();
            input.setSelectionRange(input.value.length, input.value.length);
        }

        function maybeShowSlashMenu() {
            if (!slashCommands.length || !input) return;
            var val = input.value;
            // Only when the whole input is a single "/word" token (no spaces/newlines).
            if (/^\/[\w-]*$/.test(val)) {
                renderSlashMenu(val);
            } else {
                hideSlashMenu();
            }
        }

        function scrollToBottom() {
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        }

        function trim(str, max) {
            str = String(str);
            return str.length > max ? str.slice(0, max - 1).trimEnd() + '…' : str;
        }

        // ----------------------------------------------------------------
        // Wiring
        // ----------------------------------------------------------------

        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                send();
            });
        }

        if (stopBtn) {
            stopBtn.addEventListener('click', function () {
                cancelActiveRun();
            });
        }

        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () {
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                handleFiles(fileInput.files);
                fileInput.value = '';
            });
        }

        if (thread) {
            ['dragenter', 'dragover'].forEach(function (eventName) {
                thread.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    thread.classList.add('is-dragging');
                });
            });
            ['dragleave', 'drop'].forEach(function (eventName) {
                thread.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    thread.classList.remove('is-dragging');
                    if (eventName === 'drop' && e.dataTransfer && e.dataTransfer.files) {
                        handleFiles(e.dataTransfer.files);
                    }
                });
            });
        }

        if (input) {
            input.addEventListener('keydown', function (e) {
                if (slashOpen()) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setSlashActive((slashActive + 1) % slashItems.length);
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setSlashActive((slashActive - 1 + slashItems.length) % slashItems.length);
                        return;
                    }
                    if (e.key === 'Enter' || e.key === 'Tab') {
                        if (slashActive >= 0 && slashItems[slashActive]) {
                            e.preventDefault();
                            applySlashCommand(slashItems[slashActive].cmd);
                            return;
                        }
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        hideSlashMenu();
                        return;
                    }
                }
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    send();
                }
            });
            input.addEventListener('input', function () {
                autoGrow();
                maybeShowSlashMenu();
            });
            input.addEventListener('blur', function () {
                // Delay so a click on a menu item can fire first.
                setTimeout(hideSlashMenu, 120);
            });
        }

        if (newChatBtn) {
            newChatBtn.addEventListener('click', function () {
                newChat();
            });
        }

        if (historyBtn) {
            historyBtn.addEventListener('click', openHistory);
        }
        if (historySearch) {
            historySearch.addEventListener('input', function () {
                historyFilter = historySearch.value.trim().toLowerCase();
                // Instant local filter for snappy feedback on the loaded list…
                renderHistory(conversationItems);
                // …plus a debounced deep search that reaches into message
                // content on the server, so matches in conversation text (not
                // just titles) are found too.
                scheduleHistorySearch(historySearch.value.trim());
            });
            historySearch.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeHistory();
                }
            });
        }
        if (historyModal) {
            historyModal.addEventListener('click', function (e) {
                if (e.target && e.target.closest('[data-wpa-close-history]')) {
                    closeHistory();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !historyModal.hidden) {
                    e.preventDefault();
                    closeHistory();
                }
            }, true);
        }

        // --- Init ---
        loadConversations();
        newChat();
        if (window.location.hash === '#history') {
            window.setTimeout(openHistory, 400);
        }
    });
})();
