<?php
require_once 'includes/db_connect.php';
require_once 'api/session_manager.php';

// 1. LOGIN CHECK
if (!isset($_SESSION['userType']) || $_SESSION['userType'] !== 'admin' || !isset($_SESSION['adminUser'])) {
    header("Location: admin.php");
    exit;
}

$myLevel = (int)$_SESSION['adminUser']['level'];

if ($myLevel !== 5) {
    header("Location: admin.php"); 
    exit;
}

$current_session = get_current_session($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Accounts Dashboard - GMPS</title>
    <?php include 'includes/meta.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <style>
        :root { --acc-primary: #0f172a; --acc-accent: #3b82f6; --acc-success: #10b981; --acc-danger: #ef4444; }
        body { background-color: #f1f5f9; font-family: 'Poppins', sans-serif; }
        
        /* Layout */
        .acc-wrapper { display: flex; height: 100vh; overflow: hidden; }
        .acc-sidebar { width: 250px; background: var(--acc-primary); color: white; flex-shrink: 0; display: flex; flex-direction: column; }
        .acc-main { flex: 1; overflow-y: auto; padding: 2rem; }
        
        /* Sidebar */
        .acc-brand { padding: 1.5rem; font-size: 1.2rem; font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .acc-menu { padding: 1rem 0; flex: 1; }
        .acc-item { padding: 12px 24px; cursor: pointer; display: flex; align-items: center; gap: 10px; color: #94a3b8; transition: 0.2s; }
        .acc-item:hover, .acc-item.active { background: rgba(255,255,255,0.1); color: white; border-left: 4px solid var(--acc-accent); }
        
        /* Session Switcher */
        .session-box { padding: 1rem; border-top: 1px solid rgba(255,255,255,0.1); }
        .session-select { width: 100%; padding: 8px; border-radius: 6px; background: #1e293b; color: white; border: 1px solid #334155; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
        .stat-val { font-size: 2rem; font-weight: 700; color: var(--acc-primary); margin: 5px 0; }
        .stat-label { font-size: 0.9rem; color: #64748b; font-weight: 500; }
        .stat-icon { position: absolute; right: -10px; bottom: -10px; font-size: 5rem; opacity: 0.05; color: black; }

        /* Tables */
        .data-table { width: 100%; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .data-table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .data-table td { padding: 12px 16px; border-top: 1px solid #e2e8f0; font-size: 0.9rem; color: #334155; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-pending { background: #fff7ed; color: #c2410c; }
        .badge-success { background: #f0fdf4; color: #15803d; }

        /* Modals */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; width: 90%; max-width: 500px; padding: 2rem; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

<div class="acc-wrapper">
    <aside class="acc-sidebar">
        <div class="acc-brand">GMPS Accounts</div>
        <div class="acc-menu">
            <div class="acc-item active" onclick="switchTab('dashboard')"><span></span> Dashboard</div>
            <div class="acc-item" onclick="switchTab('feemaster')"><span></span> Fee Structure</div>
            <div class="acc-item" onclick="switchTab('students')"><span></span> Student Manager</div>
            <div class="acc-item" onclick="switchTab('collect')"><span></span> Collect Fee</div>
            <div class="acc-item" onclick="switchTab('verify')"><span></span> Verify Online <span id="pendingBadge" class="badge badge-danger" style="display:none;">0</span></div>
        </div>
        <div class="session-box">
            <label style="font-size:0.8rem; color:#94a3b8; display:block; margin-bottom:5px;">Active Session</label>
            <select id="sessionSelect" class="session-select" onchange="loadDashboard()">
                </select>
        </div>
    </aside>

    <main class="acc-main">
        
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
        <h2 id="pageTitle" style="font-size:1.8rem; font-weight:700; color:var(--acc-primary);">Overview</h2>
        <div style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:0.9rem; color:#64748b;">Logged in as <strong>Accountant</strong></span>
            <a href="admin.php" style="background:white; padding:8px 16px; border-radius:8px; text-decoration:none; color:var(--acc-primary); border:1px solid #e2e8f0;">Exit</a>
        </div>
    </div>

    <div id="view_dashboard" class="view-section">
        <div class="stats-grid">
            <div class="stat-card" style="border-bottom: 4px solid var(--acc-success);">
                <div class="stat-label">Total Collected (Session)</div>
                <div class="stat-val" id="valCollected">₹0</div>
                <div class="stat-icon">₹</div>
            </div>
            <div class="stat-card" style="border-bottom: 4px solid var(--acc-accent);">
                <div class="stat-label">Total Expected (Session)</div>
                <div class="stat-val" id="valExpected">₹0</div>
                <div class="stat-icon">📊</div>
            </div>
            <div class="stat-card" style="border-bottom: 4px solid var(--acc-danger);">
                <div class="stat-label">Pending Verification</div>
                <div class="stat-val" id="valPending">0</div>
                <div class="stat-icon">⏳</div>
            </div>
        </div>
    </div>

    <div id="view_feemaster" class="view-section" style="display:none;">
        <div class="data-table">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Tuition Fee (Monthly)</th>
                        <th>Annual/Session Fee (Yearly)</th>
                        <th>Exam Fee (Yearly)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="feeMasterBody"></tbody>
            </table>
        </div>
    </div>

    <div id="view_students" class="view-section" style="display:none;">
        <div style="display:grid; grid-template-columns: 300px 1fr; gap:20px;">
            <div style="background:white; padding:1.5rem; border-radius:12px; height:fit-content;">
                <h3>Find Student</h3>
                <input type="text" id="man_search" placeholder="Name or ID..." onkeyup="searchStudent(this.value, 'manager')" 
                    style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                <div id="man_results" style="border:1px solid #eee; display:none; max-height:300px; overflow-y:auto; margin-top:5px;"></div>
            </div>

            <div id="man_details" style="display:none;">
                <div style="background:white; padding:1.5rem; border-radius:12px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between;">
                        <div style="display:flex; gap:15px; align-items:center;">
                            <img id="man_img" src="" style="width:60px; height:60px; border-radius:50%; object-fit:cover;">
                            <div>
                                <h3 id="man_name" style="margin:0;"></h3>
                                <p id="man_class" style="margin:0; color:#666;"></p>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <small>Current Balance</small>
                            <div id="man_balance" style="font-size:1.5rem; font-weight:bold;">₹0</div>
                        </div>
                    </div>
                    <hr style="margin:15px 0; border:0; border-top:1px solid #eee;">
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div>
                            <label style="font-size:0.8rem; font-weight:bold;">Transport Fee (Monthly)</label>
                            <div style="display:flex; gap:5px;">
                                <input type="number" id="man_transport" style="padding:8px; width:100px; border:1px solid #ccc; border-radius:4px;">
                                <button onclick="saveStudentSettings()" style="padding:8px; background:var(--acc-accent); color:white; border:none; border-radius:4px;">Save</button>
                            </div>
                        </div>
                        <div>
                            </div>
                    </div>
                </div>

                <div class="data-table">
                    <table style="width:100%;">
                        <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Amount</th></tr></thead>
                        <tbody id="man_ledger_body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="view_collect" class="view-section" style="display:none;">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div style="background:white; padding:1.5rem; border-radius:12px;">
                <h3>1. Select Student</h3>
                <input type="text" id="calc_search" placeholder="Type Name or ID..." onkeyup="searchStudent(this.value, 'calc')" 
                    style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                <div id="calc_results" style="border:1px solid #eee; display:none; max-height:200px; overflow-y:auto; margin-top:5px;"></div>

                <div id="studentCard" style="display:none; margin-top:15px; background:#f0f9ff; padding:10px; border-radius:8px;">
                    <h4 id="calc_name" style="margin:0;"></h4>
                    <p id="calc_class" style="margin:0; font-size:0.9rem; color:#666;"></p>
                    <div style="margin-top:10px; display:flex; gap:10px;">
                        <div style="font-size:0.8rem;">Monthly: <strong id="rate_monthly">₹0</strong></div>
                        <div style="font-size:0.8rem;">Transport: <strong id="rate_transport_display">₹0</strong></div>
                    </div>
                    <input type="hidden" id="sel_id_calc">
                </div>
            </div>

            <div style="background:white; padding:1.5rem; border-radius:12px;">
                <h3>2. Calculate Payment</h3>
                <label>Select Months:</label>
                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:5px; margin:10px 0;">
                    <?php 
                    $months = ['Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];
                    foreach($months as $m) echo "<label style='font-size:0.9rem;'><input type='checkbox' class='month-chk' value='$m' onchange='recalcTotal()'> $m</label>";
                    ?>
                </div>
                <hr>
                <div style="display:flex; justify-content:space-between;"><span>Tuition Total:</span><span id="disp_tuition">₹0</span></div>
                <div style="display:flex; justify-content:space-between;"><span>Transport Total:</span><span id="disp_transport">₹0</span></div>
                <div style="display:flex; justify-content:space-between; font-weight:bold; font-size:1.1rem; margin-top:10px;"><span>Total Demand:</span><span id="disp_total">₹0</span></div>

                <div style="margin-top:15px; background:#fff7ed; padding:10px; border-radius:8px;">
                    <label style="font-weight:bold; color:#c2410c;">Amount Collecting (₹)</label>
                    <input type="number" id="collectInput" style="width:100%; padding:10px; font-size:1.2rem; border:1px solid #c2410c; border-radius:6px;" onkeyup="checkDue()">
                    <div id="dueWarning" style="color:#ef4444; font-size:0.8rem; margin-top:5px; display:none;">Due Balance: ₹<span id="dueAmt">0</span></div>
                </div>
                
                <input type="text" id="collectRemarks" placeholder="Remarks" style="width:100%; margin-top:10px; padding:8px;">
                <div style="display:flex; gap:10px; margin-top:15px;">
                    <button onclick="processPayment(false)" style="flex:1; background:var(--acc-primary); color:white; padding:12px; border:none; border-radius:6px;">Accept Payment</button>
                    <button onclick="processPayment(true)" style="flex:1; background:#64748b; color:white; padding:12px; border:none; border-radius:6px;">Give Discount</button>
                </div>
            </div>
        </div>
    </div>

    <div id="view_verify" class="view-section" style="display:none;">
        <div class="data-table">
            <table style="width:100%;">
                <thead><tr><th>Date</th><th>Student</th><th>Amount</th><th>Mode</th><th>Action</th></tr></thead>
                <tbody id="verifyTableBody"></tbody>
            </table>
        </div>
    </div>

    </main>
</div>

<script>
    let activeSession = '<?= $current_session ?>';
    
    document.addEventListener('DOMContentLoaded', () => { loadDashboard(); });

    // --- TAB SWITCHER ---
    function switchTab(tab) {
        document.querySelectorAll('.view-section').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.acc-item').forEach(el => el.classList.remove('active'));
        
        document.getElementById('view_' + tab).style.display = 'block';
        // Highlight sidebar item logic (simplified)
        // You can add logic to highlight based on text content or ID if needed
        
        if(tab === 'dashboard') loadDashboard();
        if(tab === 'feemaster') loadFeeMaster();
        if(tab === 'verify') loadVerifyTable();
    }

    // --- GLOBAL: SEARCH LOGIC (FIXED) ---
    let debounceTimer;
    function searchStudent(query, context) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(async () => {
            const resBoxId = context === 'calc' ? 'calc_results' : 'man_results';
            const resBox = document.getElementById(resBoxId);
            
            if(query.length < 2) { resBox.style.display='none'; return; }
            
            const res = await fetch(`api/accounts_api.php?action=search_student&query=${query}`);
            const json = await res.json();
            
            resBox.innerHTML = '';
            resBox.style.display = 'block';
            
            if(json.data.length === 0) {
                resBox.innerHTML = '<div style="padding:10px;">No students found</div>';
                return;
            }

            json.data.forEach(s => {
                const div = document.createElement('div');
                div.style.cssText = 'padding:10px; border-bottom:1px solid #eee; cursor:pointer; display:flex; align-items:center; gap:10px; background:white;';
                div.innerHTML = `<img src="${s.profile_pic}" style="width:30px; height:30px; border-radius:50%;"> <div><strong>${s.name}</strong> <span style="font-size:0.8rem; color:#666;">(${s.class_name})</span></div>`;
                
                // THE FIX: Context-aware click handler
                div.onclick = () => {
                    resBox.style.display = 'none';
                    if (context === 'calc') selectStudentForCalc(s);
                    if (context === 'manager') loadStudentManager(s.id);
                };
                resBox.appendChild(div);
            });
        }, 300);
    }

    // --- 1. DASHBOARD ---
    async function loadDashboard() {
        const sess = document.getElementById('sessionSelect').value || activeSession;
        activeSession = sess;
        const res = await fetch(`api/accounts_api.php?action=get_stats&session=${sess}`);
        const json = await res.json();
        if (json.status === 'success') {
            document.getElementById('valCollected').innerText = '₹' + json.data.total_collected.toLocaleString('en-IN');
            document.getElementById('valExpected').innerText = '₹' + json.data.total_expected.toLocaleString('en-IN');
            document.getElementById('valPending').innerText = json.data.pending_count;
            if(json.data.pending_count > 0) {
                document.getElementById('pendingBadge').style.display = 'inline-block';
                document.getElementById('pendingBadge').innerText = json.data.pending_count;
            }
            // Populate Session Dropdown
            const sel = document.getElementById('sessionSelect');
            if(sel.options.length === 0) {
                json.data.available_sessions.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s;
                    opt.innerText = s;
                    if(s === activeSession) opt.selected = true;
                    sel.appendChild(opt);
                });
            }
        }
    }

    // --- 2. FEE MASTER ---
    async function loadFeeMaster() {
        const res = await fetch(`api/accounts_api.php?action=get_fee_master&session=${activeSession}`);
        const json = await res.json();
        const tbody = document.getElementById('feeMasterBody');
        tbody.innerHTML = '';
        
        json.data.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${row.class_name}</strong></td>
                <td><input type="number" id="tf_${row.class_id}" value="${row.tuition_fee}" style="width:80px;"></td>
                <td><input type="number" id="af_${row.class_id}" value="${row.annual_fee}" style="width:80px;"></td>
                <td><input type="number" id="ef_${row.class_id}" value="${row.exam_fee}" style="width:80px;"></td>
                <td><button onclick="saveFeeRow(${row.class_id})" style="padding:5px 10px; background:var(--acc-accent); color:white; border:none; border-radius:4px;">Save</button></td>
            `;
            tbody.appendChild(tr);
        });
    }

    async function saveFeeRow(cid) {
        const payload = {
            action: 'save_fee_master',
            session: activeSession,
            class_id: cid,
            tuition_fee: document.getElementById(`tf_${cid}`).value,
            annual_fee: document.getElementById(`af_${cid}`).value,
            exam_fee: document.getElementById(`ef_${cid}`).value
        };
        await fetch('api/accounts_api.php', { method: 'POST', body: JSON.stringify(payload) });
        alert("Fee updated for this class.");
    }

    // --- 3. STUDENT MANAGER ---
    let currentManStudentId = 0;
    async function loadStudentManager(sid) {
        currentManStudentId = sid;
        const res = await fetch(`api/accounts_api.php?action=get_student_details&student_id=${sid}&session=${activeSession}`);
        const json = await res.json();
        
        if(json.status === 'success') {
            document.getElementById('man_details').style.display = 'block';
            document.getElementById('man_name').innerText = json.student.name;
            document.getElementById('man_class').innerText = json.student.class_name;
            document.getElementById('man_img').src = json.student.profile_pic;
            document.getElementById('man_balance').innerText = '₹' + json.balance;
            
            // Ledger
            const tbody = document.getElementById('man_ledger_body');
            tbody.innerHTML = '';
            json.history.forEach(row => {
                const color = row.type === 'credit' ? 'green' : 'red';
                tbody.innerHTML += `
                    <tr>
                        <td>${new Date(row.created_at).toLocaleDateString()}</td>
                        <td>${row.description}</td>
                        <td style="color:${color}; font-weight:bold;">${row.type.toUpperCase()}</td>
                        <td>₹${row.amount}</td>
                    </tr>
                `;
            });

            // Settings
            document.getElementById('man_transport').value = json.transport_fee;
        }
    }

    async function saveStudentSettings() {
        if(!currentManStudentId) return;
        const amt = document.getElementById('man_transport').value;
        await fetch('api/accounts_api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'update_transport', student_id: currentManStudentId, amount: amt, session: activeSession })
        });
        alert("Transport Fee Updated!");
    }

    // --- 4. COLLECT FEE (CALCULATOR) ---
    let currentMonthlyRate = 0;
    let currentTransportRate = 0;

    async function selectStudentForCalc(s) {
        document.getElementById('calc_name').innerText = s.name;
        document.getElementById('calc_class').innerText = s.class_name;
        document.getElementById('studentCard').style.display = 'block';
        document.getElementById('sel_id_calc').value = s.id;
        
        const res = await fetch(`api/accounts_api.php?action=get_dues_breakdown&student_id=${s.id}&session=${activeSession}`);
        const json = await res.json();
        
        currentMonthlyRate = json.monthly_fee;
        currentTransportRate = json.transport_fee;
        
        document.getElementById('rate_monthly').innerText = '₹' + currentMonthlyRate;
        document.getElementById('rate_transport_display').innerText = '₹' + currentTransportRate;
        
        recalcTotal();
    }

    function recalcTotal() {
        const count = document.querySelectorAll('.month-chk:checked').length;
        const tTotal = count * currentMonthlyRate;
        const trTotal = count * currentTransportRate;
        const grand = tTotal + trTotal;
        
        document.getElementById('disp_tuition').innerText = `₹${tTotal}`;
        document.getElementById('disp_transport').innerText = `₹${trTotal}`;
        document.getElementById('disp_total').innerText = '₹' + grand;
        document.getElementById('collectInput').value = grand;
        checkDue();
    }

    function checkDue() {
        const total = parseFloat(document.getElementById('disp_total').innerText.replace('₹',''));
        const paying = parseFloat(document.getElementById('collectInput').value) || 0;
        if (paying < total) {
            document.getElementById('dueWarning').style.display = 'block';
            document.getElementById('dueAmt').innerText = (total - paying);
        } else {
            document.getElementById('dueWarning').style.display = 'none';
        }
    }

    async function processPayment(isDiscount) {
        const sid = document.getElementById('sel_id_calc').value;
        const amt = document.getElementById('collectInput').value;
        const remarks = document.getElementById('collectRemarks').value;
        const months = Array.from(document.querySelectorAll('.month-chk:checked')).map(cb => cb.value);
        
        if (!sid || amt <= 0) { alert("Invalid Payment Details"); return; }

        await fetch('api/accounts_api.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'collect_payment_complex',
                student_id: sid,
                paid_amount: amt,
                total_due: document.getElementById('disp_total').innerText.replace('₹',''),
                months: months,
                remarks: remarks,
                is_discount: isDiscount,
                session: activeSession
            })
        });
        
        alert("Transaction Recorded Successfully");
        document.querySelectorAll('.month-chk').forEach(cb => cb.checked = false);
        recalcTotal();
        document.getElementById('calc_search').value = '';
        document.getElementById('studentCard').style.display = 'none';
    }

    // --- 5. VERIFY ONLINE ---
    async function loadVerifyTable() {
        const res = await fetch(`api/accounts_api.php?action=get_pending`);
        const json = await res.json();
        const tbody = document.getElementById('verifyTableBody');
        tbody.innerHTML = '';
        if(json.data.length === 0) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No pending payments</td></tr>'; return; }
        
        json.data.forEach(t => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${new Date(t.created_at).toLocaleDateString()}</td>
                <td><strong>${t.student_name}</strong><br><small>${t.class_name}</small></td>
                <td style="color:var(--acc-success); font-weight:bold;">₹${t.amount}</td>
                <td>${t.mode.toUpperCase()}<br><small>${t.utr_number}</small></td>
                <td>
                    <button onclick="verifyTx(${t.id}, 'approved')" style="background:var(--acc-success); color:white; border:none; padding:5px 10px; border-radius:4px;">Approve</button>
                    <button onclick="verifyTx(${t.id}, 'rejected')" style="background:var(--acc-danger); color:white; border:none; padding:5px 10px; border-radius:4px;">Reject</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    async function verifyTx(id, status) {
        if(!confirm(`Confirm ${status}?`)) return;
        await fetch('api/accounts_api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'verify_transaction', transaction_id: id, status: status })
        });
        loadVerifyTable();
        loadDashboard();
    }
</script>

</body>
</html>