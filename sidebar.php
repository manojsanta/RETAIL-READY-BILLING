<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user = currentUser();
$initials = getInitials($user['full_name'] ?? $user['username'] ?? 'U');
$company = getCompany();
$companyName = $company['name'] ?? 'Retail Ready';
?>
<style>
.sidebar-wrap{position:fixed;top:0;left:0;width:260px;height:100vh;background:#fff;z-index:1040;display:flex;flex-direction:column;transition:width .28s ease,transform .28s ease;border-right:1px solid #f0f0f0;font-family:'Poppins',sans-serif}
.sidebar-wrap.collapsed{width:72px;overflow:visible}
.sidebar-brand{display:flex;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid #f4f4f4;min-height:64px;flex-shrink:0}
.sidebar-brand .brand-icon{width:38px;height:38px;background:linear-gradient(135deg,#ed1a3b,#c41230);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sidebar-brand .brand-icon i{color:#fff;font-size:17px}
.sidebar-brand .brand-text{font-size:15px;font-weight:600;color:#1a1a2e;white-space:nowrap;overflow:hidden;transition:opacity .2s,width .2s}
.sidebar-wrap.collapsed .brand-text{opacity:0;width:0}
.sidebar-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 0}
.sidebar-nav::-webkit-scrollbar{width:4px}
.sidebar-nav::-webkit-scrollbar-thumb{background:#e0e0e0;border-radius:4px}
.sidebar-nav::-webkit-scrollbar-track{background:transparent}
.sidebar-wrap.collapsed .sidebar-nav{overflow:visible}
.nav-section{padding:16px 20px 6px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#aaa;white-space:nowrap;overflow:hidden;transition:opacity .2s}
.sidebar-wrap.collapsed .nav-section{opacity:0;height:0;padding:0;overflow:hidden}
.nav-item{position:relative}
.nav-item>a,.nav-item>.nav-toggle{display:flex;align-items:center;gap:12px;padding:9px 20px;color:#555;text-decoration:none;font-size:13px;font-weight:500;cursor:pointer;border:none;background:none;width:100%;text-align:left;border-radius:0;transition:all .15s ease;white-space:nowrap;overflow:hidden}
.nav-item>a:hover,.nav-item>.nav-toggle:hover{background:#fef2f2;color:#ed1a3b}
.nav-item.active>a,.nav-item.active>.nav-toggle{background:#fef2f2;color:#ed1a3b;font-weight:600}
.nav-item.active>a::before,.nav-item.active>.nav-toggle::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:22px;background:#ed1a3b;border-radius:0 3px 3px 0}
.nav-item>a i,.nav-item>.nav-toggle i{width:20px;text-align:center;font-size:15px;flex-shrink:0}
.nav-item>a span,.nav-item>.nav-toggle span{transition:opacity .2s;overflow:hidden}
.sidebar-wrap.collapsed .nav-item>a span,.sidebar-wrap.collapsed .nav-item>.nav-toggle span{opacity:0;width:0}
.sidebar-wrap.collapsed .nav-item>a,.sidebar-wrap.collapsed .nav-item>.nav-toggle{justify-content:center;padding:10px 0}
.sidebar-wrap.collapsed .nav-item>a i,.sidebar-wrap.collapsed .nav-item>.nav-toggle i{margin:0}
.sidebar-wrap.collapsed .toggle-icon{display:none!important}
.nav-item>.nav-toggle{font-family:inherit;color:#555}
.nav-item>.nav-toggle .toggle-icon{margin-left:auto;transition:transform .25s;font-size:9px;color:#ed1a3b;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;background:rgba(237,26,59,0.1);border-radius:50%;flex-shrink:0}
.nav-item.open>.nav-toggle .toggle-icon{transform:rotate(45deg)}
.submenu{max-height:0;overflow:hidden;transition:max-height .3s ease;background:#fafafa}
.sidebar-wrap.collapsed .submenu{max-height:0!important;overflow:hidden!important}
.sidebar-wrap.collapsed .nav-item.has-sub{overflow:visible}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu{display:block;position:absolute;left:72px;top:-1px;min-width:220px;max-height:none!important;background:#fff;border:1px solid #eee;border-radius:0 10px 10px 0;box-shadow:4px 4px 16px rgba(0,0,0,.1);z-index:1060;padding:10px 0;overflow:hidden}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>a,.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>.nav-toggle{justify-content:flex-start;padding:9px 16px}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>a span,.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>.nav-toggle span{opacity:1;width:auto}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>a i{font-size:13px;color:#bbb;width:20px;text-align:center}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item>a:hover{background:#fef2f2;color:#ed1a3b}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>.submenu .nav-item.active>a{color:#ed1a3b;background:rgba(237,26,59,.04);font-weight:600}
.sidebar-wrap.collapsed .nav-item.has-sub:hover>a{background:#fef2f2;color:#ed1a3b}
.submenu .nav-item>a{padding-left:52px;font-size:12.5px;color:#777}
.submenu .nav-item>a i{font-size:12px;color:#bbb}
.submenu .nav-item.active>a{color:#ed1a3b;background:rgba(237,26,59,.04)}
.submenu .nav-item.active>a i{color:#ed1a3b}
.sidebar-footer-wrap{border-top:1px solid #f4f4f4;padding:8px 0;flex-shrink:0}
.sidebar-footer-wrap .nav-item>a{padding:9px 20px}
.sidebar-footer-wrap .nav-item>a i{color:#888}
.sidebar-collapse-btn{width:100%;background:none;border:none;padding:0;cursor:pointer}
@media(max-width:991px){
.sidebar-wrap{transform:translateX(-100%)}
.sidebar-wrap.mobile-open{transform:translateX(0)}
.sidebar-wrap .toggle-icon{display:none}
.sidebar-wrap.collapsed .nav-item>a span,.sidebar-wrap.collapsed .nav-item>.nav-toggle span{opacity:1;width:auto}
.sidebar-wrap.collapsed .nav-item>a,.sidebar-wrap.collapsed .nav-item>.nav-toggle{justify-content:flex-start;padding:9px 20px}
.sidebar-wrap.collapsed .nav-item>a i,.sidebar-wrap.collapsed .nav-item>.nav-toggle i{width:20px;text-align:center;font-size:15px;margin:0}
.sidebar-wrap.collapsed .nav-section{opacity:1;height:auto;padding:16px 20px 6px}
.sidebar-wrap.collapsed .brand-text{opacity:1;width:auto}
.sidebar-wrap.collapsed .submenu .nav-item>a{padding-left:52px}
}
</style>

<div class="sidebar-wrap" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-desktop"></i></div>
        <div class="brand-text"><?php echo htmlspecialchars($companyName); ?></div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <div class="nav-item<?php echo $current_page === 'dashboard.php' ? ' active' : ''; ?>">
            <a href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
        </div>
<div class="nav-section">Parties</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['parties.php','party_add.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-users"></i><span>Parties</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'parties.php' ? ' active' : ''; ?>">
                    <a href="parties.php"><i class="fas fa-address-book"></i><span>All Parties</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'party_add.php' ? ' active' : ''; ?>">
                    <a href="party_add.php"><i class="fas fa-user-plus"></i><span>Add Party</span></a>
                </div>
            </div>
        </div>
        <div class="nav-section">Items</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['items.php','item_add.php','categories.php','units.php','unit_add.php','stock.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-box"></i><span>Items</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'items.php' ? ' active' : ''; ?>">
                    <a href="items.php"><i class="fas fa-list"></i><span>All Items</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'item_add.php' ? ' active' : ''; ?>">
                    <a href="item_add.php"><i class="fas fa-plus-square"></i><span>Add Item</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'categories.php' ? ' active' : ''; ?>">
                    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
                </div>
                <div class="nav-item<?php echo in_array($current_page, ['units.php','unit_add.php','unit_edit.php']) ? ' active' : ''; ?>">
                    <a href="units.php"><i class="fas fa-ruler"></i><span>Units</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'stock.php' ? ' active' : ''; ?>">
                    <a href="stock.php"><i class="fas fa-warehouse"></i><span>Stock Summary</span></a>
                </div>
            </div>
        </div>
<div class="nav-section">Purchase</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['purchases.php','purchase_add.php','payment_out.php','purchase_return.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-shopping-cart"></i><span>Purchases</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'purchases.php' ? ' active' : ''; ?>">
                    <a href="purchases.php"><i class="fas fa-list"></i><span>Purchase Bills</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'purchase_add.php' ? ' active' : ''; ?>">
                    <a href="purchase_add.php"><i class="fas fa-plus-circle"></i><span>New Purchase</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'payment_out.php' ? ' active' : ''; ?>">
                    <a href="payment_out.php"><i class="fas fa-money-bill-wave"></i><span>Payment Out</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'purchase_return.php' ? ' active' : ''; ?>">
                    <a href="purchase_return.php"><i class="fas fa-undo-alt"></i><span>Purchase Returns</span></a>
                </div>
            </div>
        </div>

        <div class="nav-section">Sale</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['sales.php','sale_add.php','payment_in.php','sale_return.php','estimates.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-file-invoice-dollar"></i><span>Sales</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'sales.php' ? ' active' : ''; ?>">
                    <a href="sales.php"><i class="fas fa-list"></i><span>Sale Invoices</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'sale_add.php' ? ' active' : ''; ?>">
                    <a href="sale_add.php"><i class="fas fa-plus-circle"></i><span>New Sale</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'payment_in.php' ? ' active' : ''; ?>">
                    <a href="payment_in.php"><i class="fas fa-hand-holding-usd"></i><span>Payment In</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'sale_return.php' ? ' active' : ''; ?>">
                    <a href="sale_return.php"><i class="fas fa-undo"></i><span>Sale Returns</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'estimates.php' ? ' active' : ''; ?>">
                    <a href="estimates.php"><i class="fas fa-file-contract"></i><span>Estimates</span></a>
                </div>
            </div>
        </div>

        
        

        
        <div class="nav-section">Finance</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['expenses.php','cash_bank.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-receipt"></i><span>Finance</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'expenses.php' ? ' active' : ''; ?>">
                    <a href="expenses.php"><i class="fas fa-receipt"></i><span>Expenses</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'cash_bank.php' ? ' active' : ''; ?>">
                    <a href="cash_bank.php"><i class="fas fa-university"></i><span>Cash & Bank</span></a>
                </div>
            </div>
        </div>

        <div class="nav-section">Reports</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['report_sales.php','report_purchase.php','report_profit_loss.php','report_party.php','report_stock.php','report_expense.php','report_daybook.php','report_gst.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-chart-pie"></i><span>Reports</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'report_sales.php' ? ' active' : ''; ?>">
                    <a href="report_sales.php"><i class="fas fa-chart-line"></i><span>Sales Report</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_purchase.php' ? ' active' : ''; ?>">
                    <a href="report_purchase.php"><i class="fas fa-chart-bar"></i><span>Purchase Report</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_profit_loss.php' ? ' active' : ''; ?>">
                    <a href="report_profit_loss.php"><i class="fas fa-chart-pie"></i><span>Profit & Loss</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_party.php' ? ' active' : ''; ?>">
                    <a href="report_party.php"><i class="fas fa-address-card"></i><span>Party Report</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_stock.php' ? ' active' : ''; ?>">
                    <a href="report_stock.php"><i class="fas fa-box-open"></i><span>Stock Report</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_expense.php' ? ' active' : ''; ?>">
                    <a href="report_expense.php"><i class="fas fa-money-check-alt"></i><span>Expense Report</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_daybook.php' ? ' active' : ''; ?>">
                    <a href="report_daybook.php"><i class="fas fa-book"></i><span>Day Book</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'report_gst.php' ? ' active' : ''; ?>">
                    <a href="report_gst.php"><i class="fas fa-file-alt"></i><span>GST Reports</span></a>
                </div>
            </div>
        </div>

        <div class="nav-section">More</div>
        <div class="nav-item has-sub<?php echo in_array($current_page, ['delivery_challans.php','settings.php','backup.php','financial_year_manage.php']) ? ' open' : ''; ?>">
            <button class="nav-toggle" onclick="toggleSub(this)">
                <i class="fas fa-ellipsis-h"></i><span>More</span><i class="fas fa-plus toggle-icon" style="font-size:10px;"></i>
            </button>
            <div class="submenu">
                <div class="nav-item<?php echo $current_page === 'financial_year_manage.php' ? ' active' : ''; ?>">
                    <a href="financial_year_manage.php"><i class="fas fa-calendar-alt"></i><span>Financial Years</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'delivery_challans.php' ? ' active' : ''; ?>">
                    <a href="delivery_challans.php"><i class="fas fa-truck"></i><span>Delivery Challan</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'settings.php' ? ' active' : ''; ?>">
                    <a href="settings.php"><i class="fas fa-cog"></i><span>Settings</span></a>
                </div>
                <div class="nav-item<?php echo $current_page === 'backup.php' ? ' active' : ''; ?>">
                    <a href="backup.php"><i class="fas fa-database"></i><span>Backup</span></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="sidebar-footer-wrap">
        <div class="nav-item">
            <a href="logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>
</div>

<div class="mobile-overlay" id="mobileOverlay" onclick="closeSidebar()"></div>

<script>
function toggleSub(btn){
    var item=btn.parentElement;
    var sub=item.querySelector('.submenu');
    if(!sub)return;
    if(item.classList.contains('open')){
        sub.style.maxHeight='0px';
        item.classList.remove('open');
    }else{
        sub.style.maxHeight=sub.scrollHeight+'px';
        item.classList.add('open');
    }
}
function initSubs(){
    var items=document.querySelectorAll('.nav-item.open');
    for(var i=0;i<items.length;i++){
        var s=items[i].querySelector('.submenu');
        if(s)s.style.maxHeight=s.scrollHeight+'px';
    }
}
function toggleSidebar(){
    var sb=document.getElementById('sidebar');
    if(window.innerWidth<992){
        sb.classList.toggle('mobile-open');
        document.getElementById('mobileOverlay').classList.toggle('show');
        document.body.style.overflow=sb.classList.contains('mobile-open')?'hidden':'';
    }else{
        sb.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed');
        if(sb.classList.contains('collapsed')){
            var all=sb.querySelectorAll('.submenu');
            for(var i=0;i<all.length;i++){
                all[i].style.maxHeight='0px';
            }
        }else{initSubs();}
    }
}
function closeSidebar(){
    var sb=document.getElementById('sidebar');
    sb.classList.remove('mobile-open');
    document.getElementById('mobileOverlay').classList.remove('show');
    document.body.style.overflow='';
}
window.addEventListener('resize',function(){
    if(window.innerWidth>=992){closeSidebar();document.body.style.overflow='';}
});
document.addEventListener('DOMContentLoaded',function(){initSubs();});
</script>
