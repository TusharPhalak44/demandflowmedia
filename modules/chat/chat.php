<?php
/**
 * Professional Chat Interface
 * 
 * Provides real-time messaging, user presence tracking, and attachment support.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensureChatSchema();
ensureCsrfToken();

$user = getCurrentUser();
?>
<?php $pageTitle = 'Chat'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
    <div class="container-fluid px-0">
        <div class="chat-container">
            <!-- Sidebar -->
            <div class="chat-sidebar">
                <div class="sidebar-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 fw-bold">Messages</h5>
                        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="modal" data-bs-target="#createGroupModal" title="New Group">
                            <i class="bi bi-people"></i>
                        </button>
                    </div>
                    <div class="mt-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="chatSearch" placeholder="Search users / groups">
                            <button class="btn btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="chatFilterBtn">All</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button class="dropdown-item" type="button" onclick="setChatFilter('all')">All</button></li>
                                <li><button class="dropdown-item" type="button" onclick="setChatFilter('direct')">Direct Chats</button></li>
                                <li><button class="dropdown-item" type="button" onclick="setChatFilter('groups')">Group Chats</button></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="user-list" id="userList">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    </div>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="chat-main">
                <div id="chatInterface" class="chat-interface d-none">
                    <div class="chat-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="user-avatar" id="activePeerAvatar"></div>
                            <div>
                                <h6 class="mb-0 fw-bold" id="activePeerName"></h6>
                                <span class="user-status d-flex align-items-center gap-1">
                                    <span id="activePeerStatusDot" class="status-indicator status-dot-sm position-static"></span>
                                    <span id="activePeerStatusText"></span>
                                </span>
                            </div>
                        </div>
                        <div class="chat-actions">
                            <button class="btn btn-sm btn-light border d-none" id="groupMembersBtn" type="button" data-bs-toggle="modal" data-bs-target="#groupMembersModal" title="Members">
                                <i class="bi bi-people"></i>
                            </button>
                            <button class="btn btn-sm btn-light border" onclick="loadChatList()"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <!-- Messages loaded via JS -->
                    </div>

                    <div class="chat-footer">
                        <form id="chatForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="receiver_id" id="receiverIdInput">
                            <input type="hidden" name="group_id" id="groupIdInput">
                            <div class="chat-input-wrapper">
                                <label class="attachment-btn mb-0" for="attachmentInput">
                                    <i class="bi bi-paperclip fs-5"></i>
                                    <input type="file" name="attachment" id="attachmentInput" class="d-none">
                                </label>
                                <input type="text" class="form-control" name="message" id="messageInput" placeholder="Type your message here..." autocomplete="off">
                                <button type="submit" class="btn btn-primary rounded-pill px-4">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                            <div id="attachmentPreview" class="small text-muted mt-2 d-none">
                                <i class="bi bi-file-earmark-check me-1"></i> <span id="fileName"></span>
                                <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" onclick="clearAttachment()">Remove</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Empty State -->
                <div class="empty-chat" id="emptyChat">
                    <i class="bi bi-chat-dots"></i>
                    <h4>Select a conversation</h4>
                    <p>Choose a user from the left sidebar to start messaging.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-1"></i>Create Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Group Name</label>
                            <input type="text" class="form-control" id="newGroupName" placeholder="e.g. Operations Team">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Add Members</label>
                            <input type="text" class="form-control form-control-sm mb-2" id="groupMemberSearch" placeholder="Search users">
                            <div class="list-group" id="groupMemberPickList"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createGroup()"><i class="bi bi-check2 me-1"></i>Create</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="groupMembersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-1"></i>Group Members</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="groupMembersBody" class="text-muted">Loading...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let activeChat = null;
        let lastMessageId = 0;
        const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
        const currentUserId = <?php echo (int)$user['id']; ?>;
        let chatFilter = 'all';
        let groupRole = 'member';
        let groupId = null;
        let peerId = null;
        let allUsersForGroupPick = [];

        function getInitials(name) {
            return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
        }

        function setChatFilter(f) {
            chatFilter = f;
            const btn = document.getElementById('chatFilterBtn');
            if (btn) btn.textContent = f === 'direct' ? 'Direct' : (f === 'groups' ? 'Groups' : 'All');
            loadChatList();
        }

        function avatarHtml(name, profilePic) {
            const safeName = escapeHtml(name || '');
            if (profilePic) {
                return `<img src="../../${escapeHtml(profilePic)}" alt="${safeName}" style="width:100%;height:100%;object-fit:cover;border-radius:999px;">`;
            }
            return `<div class="w-100 h-100 d-flex align-items-center justify-content-center">${escapeHtml(getInitials(name || 'U'))}</div>`;
        }

        function groupAvatarHtml(name) {
            return `<div class="w-100 h-100 d-flex align-items-center justify-content-center"><i class="bi bi-people"></i></div>`;
        }

        function formatTime(ts) {
            if (!ts) return '';
            const d = new Date(ts.replace(' ', 'T'));
            if (isNaN(d.getTime())) return String(ts);
            return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        }

        function loadChatList() {
            const q = (document.getElementById('chatSearch')?.value || '').trim();
            fetch(`chat-list?filter=${encodeURIComponent(chatFilter)}&q=${encodeURIComponent(q)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    const list = document.getElementById('userList');
                    if (!data.ok) { list.innerHTML = '<div class="p-3 text-danger">Error loading chats</div>'; return; }
                    const direct = Array.isArray(data.direct_chats) ? data.direct_chats : [];
                    const groups = Array.isArray(data.group_chats) ? data.group_chats : [];

                    let html = '';
                    if (groups.length) {
                        html += `<div class="px-3 pt-3 pb-2 text-muted small fw-semibold">Group Chats</div>`;
                        groups.forEach(g => {
                            const isActive = activeChat && activeChat.type === 'group' && activeChat.id === g.id;
                            html += `
                                <div class="user-item ${isActive ? 'active' : ''}" data-chat-type="group" data-id="${g.id}" data-name="${escapeAttr(g.name || '')}" data-members="${g.member_count || 0}" data-role="${escapeAttr(g.member_role || 'member')}">
                                    <div class="user-avatar">
                                        ${groupAvatarHtml(g.name)}
                                    </div>
                                    <div class="user-info">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <div class="user-name text-truncate">${escapeHtml(g.name)}</div>
                                            <div class="text-muted small">${escapeHtml(formatTime(g.last_message_at))}</div>
                                        </div>
                                        <div class="user-status text-truncate">${escapeHtml(g.last_message_preview || '')}</div>
                                    </div>
                                    ${g.unread_count > 0 ? `<span class="badge rounded-pill bg-danger">${g.unread_count}</span>` : ''}
                                </div>
                            `;
                        });
                    }
                    if (direct.length) {
                        html += `<div class="px-3 pt-3 pb-2 text-muted small fw-semibold">Direct Chats</div>`;
                        direct.forEach(u => {
                            const isActive = activeChat && activeChat.type === 'direct' && activeChat.id === u.id;
                            html += `
                                <div class="user-item ${isActive ? 'active' : ''}" data-chat-type="direct" data-id="${u.id}" data-name="${escapeAttr(u.name || '')}" data-online="${u.is_online ? '1' : '0'}" data-pic="${escapeAttr(u.profile_pic || '')}">
                                    <div class="user-avatar">
                                        ${avatarHtml(u.name, u.profile_pic)}
                                        <span class="status-indicator ${u.is_online ? 'status-online' : 'status-offline'}"></span>
                                    </div>
                                    <div class="user-info">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <div class="user-name text-truncate">${escapeHtml(u.name)}</div>
                                            <div class="text-muted small">${escapeHtml(formatTime(u.last_message_at))}</div>
                                        </div>
                                        <div class="user-status text-truncate">${escapeHtml(u.last_message_preview || '')}</div>
                                    </div>
                                    ${u.unread_count > 0 ? `<span class="badge rounded-pill bg-danger">${u.unread_count}</span>` : ''}
                                </div>
                            `;
                        });
                    }
                    if (!html) html = '<div class="p-3 text-muted">No chats found.</div>';
                    list.innerHTML = html;
                    list.querySelectorAll('.user-item[data-chat-type]').forEach(el => {
                        el.addEventListener('click', () => {
                            const type = el.getAttribute('data-chat-type');
                            const id = parseInt(el.getAttribute('data-id') || '0');
                            const name = el.getAttribute('data-name') || '';
                            if (!id) return;
                            if (type === 'group') {
                                const members = parseInt(el.getAttribute('data-members') || '0');
                                const role = el.getAttribute('data-role') || 'member';
                                selectGroup(id, name, members, role);
                            } else {
                                const online = (el.getAttribute('data-online') === '1');
                                const pic = el.getAttribute('data-pic') || '';
                                selectDirect(id, name, online, pic);
                            }
                        });
                    });
                })
                .catch(() => {
                    const list = document.getElementById('userList');
                    if (list) list.innerHTML = '<div class="p-3 text-danger">Error loading chats</div>';
                });
        }

        function selectDirect(id, name, online, profilePic) {
            activeChat = { type: 'direct', id };
            peerId = id;
            groupId = null;
            groupRole = 'member';
            document.getElementById('receiverIdInput').value = id;
            document.getElementById('groupIdInput').value = '';
            document.getElementById('activePeerName').textContent = name;
            document.getElementById('activePeerAvatar').innerHTML = avatarHtml(name, profilePic || '');
            document.getElementById('activePeerStatusText').textContent = online ? 'Online' : 'Offline';
            document.getElementById('activePeerStatusDot').className = `status-indicator status-dot-sm position-static ${online ? 'status-online' : 'status-offline'}`;
            document.getElementById('groupMembersBtn').classList.add('d-none');
            
            document.getElementById('emptyChat').classList.add('d-none');
            const ci = document.getElementById('chatInterface');
            ci.classList.remove('d-none');
            ci.classList.add('d-flex');
            document.getElementById('chatMessages').innerHTML = '';
            
            lastMessageId = 0;
            fetchMessages();
            loadChatList();
        }

        function selectGroup(id, name, memberCount, memberRole) {
            activeChat = { type: 'group', id };
            groupId = id;
            peerId = null;
            groupRole = memberRole || 'member';
            document.getElementById('receiverIdInput').value = '';
            document.getElementById('groupIdInput').value = String(id);
            document.getElementById('activePeerName').textContent = name;
            document.getElementById('activePeerAvatar').innerHTML = groupAvatarHtml(name);
            document.getElementById('activePeerStatusText').textContent = `${memberCount || 0} members`;
            document.getElementById('activePeerStatusDot').className = 'status-indicator status-dot-sm position-static status-offline';
            document.getElementById('groupMembersBtn').classList.remove('d-none');

            document.getElementById('emptyChat').classList.add('d-none');
            const ci = document.getElementById('chatInterface');
            ci.classList.remove('d-none');
            ci.classList.add('d-flex');
            document.getElementById('chatMessages').innerHTML = '';
            lastMessageId = 0;
            fetchMessages();
            loadChatList();
        }

        function fetchMessages() {
            if (!activeChat) return;
            const url = activeChat.type === 'group'
                ? `chat-fetch?group_id=${activeChat.id}&since_id=${lastMessageId}`
                : `chat-fetch?peer_id=${activeChat.id}&since_id=${lastMessageId}`;
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok || !data.messages.length) return;
                    
                    const box = document.getElementById('chatMessages');
                    data.messages.forEach(m => {
                        lastMessageId = Math.max(lastMessageId, m.id);
                        const isSystem = m.message_type === 'system';
                        const isOwn = (parseInt(m.sender_id || 0) === currentUserId);
                        const canDelete = !isSystem && (isOwn || (activeChat && activeChat.type === 'group' && groupRole === 'admin'));
                        const msgDiv = document.createElement('div');
                        msgDiv.className = isSystem ? 'message message-system' : `message ${isOwn ? 'message-own' : 'message-peer'}`;
                        
                        let attachmentHtml = '';
                        if (m.attachment_path) {
                            const fileName = m.attachment_path.split('/').pop();
                            attachmentHtml = `
                                <div class="chat-attachment">
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <div class="d-flex align-items-center gap-2 text-muted small">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <span>${fileName}</span>
                                        </div>
                                        <a href="../../${m.attachment_path}" target="_blank" class="btn btn-sm btn-outline-primary" download title="Download">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    </div>
                                </div>`;
                        }

                        const avatar = isSystem
                            ? `<div class="message-avatar"></div>`
                            : `<div class="message-avatar">${avatarHtml(m.sender_name || (isOwn ? 'You' : 'User'), m.sender_profile_pic || '')}</div>`;

                        const deleteBtn = canDelete ? `<button type="button" class="btn btn-sm btn-light border message-del-btn" title="Delete" onclick="deleteMessage(${m.id})"><i class="bi bi-trash"></i></button>` : '';

                        msgDiv.innerHTML = `
                            ${isSystem ? `<div class="message-system-text">${escapeHtml(m.message || '')}</div>` : `
                                ${avatar}
                                <div class="message-bubble">
                                    <div class="message-content">
                                        ${escapeHtml(m.message || '')}
                                        ${attachmentHtml}
                                    </div>
                                    <div class="message-meta d-flex align-items-center justify-content-between gap-2">
                                        <span>${escapeHtml(m.created_at || '')}</span>
                                        <span class="d-flex align-items-center gap-2">
                                            ${deleteBtn}
                                            ${isOwn && activeChat.type === 'direct' ? `<i class="bi bi-check2-all ${m.read_at ? 'text-primary' : ''}"></i>` : ''}
                                        </span>
                                    </div>
                                </div>
                            `}
                        `;
                        box.appendChild(msgDiv);
                    });
                    box.scrollTop = box.scrollHeight;
                    loadChatList();
                })
                .catch(() => {});
        }

        document.getElementById('chatForm').onsubmit = function(e) {
            e.preventDefault();
            if (!activeChat) return;
            
            const msgInput = document.getElementById('messageInput');
            if (!msgInput.value.trim() && !document.getElementById('attachmentInput').files.length) return;

            const fd = new FormData(this);
            fetch('chat-send', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    msgInput.value = '';
                    clearAttachment();
                    fetchMessages();
                }
            }).catch(() => {});
        };

        document.getElementById('attachmentInput').onchange = function() {
            if (this.files.length) {
                document.getElementById('fileName').textContent = this.files[0].name;
                document.getElementById('attachmentPreview').classList.remove('d-none');
            }
        };

        function clearAttachment() {
            document.getElementById('attachmentInput').value = '';
            document.getElementById('attachmentPreview').classList.add('d-none');
        }

        function heartbeat() {
            fetch('chat-presence', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf_token: csrfToken, online: true })
            }).catch(() => {});
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeAttr(text) {
            return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function deleteMessage(id) {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('message_id', String(id));
            fetch('chat-delete', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok) {
                        lastMessageId = 0;
                        document.getElementById('chatMessages').innerHTML = '';
                        fetchMessages();
                    }
                })
                .catch(() => {});
        }

        function loadGroupMemberPickList() {
            fetch('chat-list-users', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d || !d.ok) return;
                    allUsersForGroupPick = (d.users || []).filter(u => u.id !== currentUserId);
                    renderGroupMemberPickList();
                })
                .catch(() => {});
        }

        function renderGroupMemberPickList() {
            const q = (document.getElementById('groupMemberSearch')?.value || '').trim().toLowerCase();
            const box = document.getElementById('groupMemberPickList');
            if (!box) return;
            const items = allUsersForGroupPick.filter(u => !q || (String(u.full_name || '').toLowerCase().includes(q)));
            box.innerHTML = items.map(u => `
                <label class="list-group-item d-flex align-items-center gap-2">
                    <input class="form-check-input me-1" type="checkbox" value="${u.id}">
                    <span class="flex-grow-1">${escapeHtml(u.full_name || '')}</span>
                    <span class="badge bg-light text-muted border">${escapeHtml(u.role || '')}</span>
                </label>
            `).join('') || '<div class="text-muted small p-2">No users</div>';
        }

        function createGroup() {
            const name = (document.getElementById('newGroupName')?.value || '').trim();
            if (!name) return;
            const checked = Array.from(document.querySelectorAll('#groupMemberPickList input[type="checkbox"]:checked')).map(el => parseInt(el.value || '0')).filter(n => n > 0);
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'create');
            fd.append('group_name', name);
            checked.forEach(id => fd.append('member_ids[]', String(id)));
            fetch('chat-groups', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok && d.group) {
                        const modalEl = document.getElementById('createGroupModal');
                        if (modalEl && window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                        document.getElementById('newGroupName').value = '';
                        document.querySelectorAll('#groupMemberPickList input[type="checkbox"]').forEach(el => el.checked = false);
                        loadChatList();
                        selectGroup(d.group.id, d.group.group_name, 0, 'admin');
                    }
                })
                .catch(() => {});
        }

        function loadGroupMembers() {
            if (!activeChat || activeChat.type !== 'group') return;
            const body = document.getElementById('groupMembersBody');
            if (body) body.textContent = 'Loading...';
            fetch(`chat-group-members?group_id=${activeChat.id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!body) return;
                    if (!d || !d.ok) { body.textContent = d && d.error ? d.error : 'Failed'; return; }
                    const members = Array.isArray(d.members) ? d.members : [];
                    const myRole = d.my_role || 'member';
                    const isAdmin = myRole === 'admin';
                    const memberIds = new Set(members.map(m => parseInt(m.user_id || 0)).filter(n => n > 0));
                    if (!allUsersForGroupPick.length) {
                        fetch('chat-list-users', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(r => r.json())
                            .then(u => { if (u && u.ok) allUsersForGroupPick = (u.users || []).filter(x => x.id !== currentUserId); })
                            .catch(()=>{});
                    }
                    const addable = allUsersForGroupPick.filter(u => !memberIds.has(u.id));
                    let html = '';
                    html += `<div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="text-muted small">${members.length} members</div>
                        <div class="badge bg-light text-muted border">${escapeHtml(myRole)}</div>
                    </div>`;
                    if (isAdmin) {
                        html += `
                            <div class="border rounded p-2 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <select class="form-select form-select-sm" id="addMemberSelect">
                                        <option value="">Add member...</option>
                                        ${addable.map(u => `<option value="${u.id}">${escapeHtml(u.full_name || '')} (${escapeHtml(u.role || '')})</option>`).join('')}
                                    </select>
                                    <button class="btn btn-sm btn-primary" type="button" data-action="add-member"><i class="bi bi-person-plus"></i></button>
                                </div>
                                <div class="text-muted small mt-2">Admins can add/remove members and assign admins.</div>
                            </div>
                        `;
                    }
                    html += `<div class="list-group">` + members.map(m => `
                        <div class="list-group-item d-flex align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:32px;height:32px;border-radius:999px;overflow:hidden;background:#e5e7eb;" class="d-flex align-items-center justify-content-center">
                                    ${avatarHtml(m.full_name || 'U', m.profile_pic || '')}
                                </div>
                                <div>
                                    <div class="fw-semibold">${escapeHtml(m.full_name || '')}</div>
                                    <div class="text-muted small">${escapeHtml(m.role || '')}</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                ${(m.role === 'admin') ? '<span class="badge bg-primary-subtle text-primary border">Admin</span>' : '<span class="badge bg-light text-muted border">Member</span>'}
                                ${isAdmin && (parseInt(m.user_id || 0) !== currentUserId) ? `
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-light border" type="button" data-action="toggle-role" data-user-id="${m.user_id}" data-next-role="${m.role === 'admin' ? 'member' : 'admin'}" title="${m.role === 'admin' ? 'Demote to member' : 'Make admin'}">
                                            <i class="bi ${m.role === 'admin' ? 'bi-person' : 'bi-person-check'}"></i>
                                        </button>
                                        <button class="btn btn-light border text-danger" type="button" data-action="remove-member" data-user-id="${m.user_id}" title="Remove">
                                            <i class="bi bi-person-dash"></i>
                                        </button>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `).join('') + `</div>`;
                    body.innerHTML = html;

                    if (isAdmin) {
                        body.querySelector('[data-action="add-member"]')?.addEventListener('click', () => {
                            const sel = body.querySelector('#addMemberSelect');
                            const uid2 = parseInt(sel?.value || '0');
                            if (!uid2) return;
                            const fd = new FormData();
                            fd.append('csrf_token', csrfToken);
                            fd.append('group_id', String(activeChat.id));
                            fd.append('action', 'add');
                            fd.append('user_id', String(uid2));
                            fetch('chat-group-members', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                .then(r => r.json())
                                .then(res => { if (res && res.ok) loadGroupMembers(); });
                        });

                        body.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const uid2 = parseInt(btn.getAttribute('data-user-id') || '0');
                                if (!uid2) return;
                                if (!confirm('Remove this user from group?')) return;
                                const fd = new FormData();
                                fd.append('csrf_token', csrfToken);
                                fd.append('group_id', String(activeChat.id));
                                fd.append('action', 'remove');
                                fd.append('user_id', String(uid2));
                                fetch('chat-group-members', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                    .then(r => r.json())
                                    .then(res => { if (res && res.ok) loadGroupMembers(); });
                            });
                        });

                        body.querySelectorAll('[data-action="toggle-role"]').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const uid2 = parseInt(btn.getAttribute('data-user-id') || '0');
                                const role2 = btn.getAttribute('data-next-role') || 'member';
                                if (!uid2) return;
                                const fd = new FormData();
                                fd.append('csrf_token', csrfToken);
                                fd.append('group_id', String(activeChat.id));
                                fd.append('action', 'set_role');
                                fd.append('user_id', String(uid2));
                                fd.append('role', role2);
                                fetch('chat-group-members', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                    .then(r => r.json())
                                    .then(res => { if (res && res.ok) loadGroupMembers(); });
                            });
                        });
                    }
                })
                .catch(() => { if (body) body.textContent = 'Failed to load members.'; });
        }

        document.getElementById('groupMembersModal').addEventListener('show.bs.modal', loadGroupMembers);

        document.getElementById('chatSearch').addEventListener('input', () => {
            clearTimeout(window.__chatSearchT);
            window.__chatSearchT = setTimeout(loadChatList, 250);
        });
        document.getElementById('groupMemberSearch')?.addEventListener('input', renderGroupMemberPickList);
        document.getElementById('createGroupModal').addEventListener('show.bs.modal', loadGroupMemberPickList);

        // Initialize
        setInterval(loadChatList, 5000);
        setInterval(fetchMessages, 3000);
        setInterval(heartbeat, 15000);
        loadChatList();
        heartbeat();

        (function initFromUrl(){
            const params = new URLSearchParams(window.location.search);
            const u = parseInt(params.get('user_id') || '0');
            const g = parseInt(params.get('group_id') || '0');
            if (g > 0) {
                selectGroup(g, 'Group', 0, 'member');
                return;
            }
            if (u > 0) {
                selectDirect(u, 'User', false, '');
            }
        })();
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
