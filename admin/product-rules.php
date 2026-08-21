<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;
use ClientVerification\Security\Csrf;

$adminId = (int) ($_SESSION['adminid'] ?? 0);
$successMsg = '';
$errorMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['cv_token'] ?? null)) {
        $errorMsg = 'Security token expired or invalid. Please try again.';
    } else {
        // 1. Add / Update Single Product Rule
        if (isset($_POST['add_single_product'])) {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $req = in_array($_POST['requirement'] ?? '', ['required', 'optional', 'not_required']) ? $_POST['requirement'] : 'required';
            if ($pid > 0) {
                Capsule::table('mod_cv_product_rules')->updateOrInsert(
                    ['product_id' => $pid],
                    ['requirement' => $req, 'updated_at' => date('Y-m-d H:i:s')]
                );
                $pname = Capsule::table('tblproducts')->where('id', $pid)->value('name') ?: 'Product #' . $pid;
                $successMsg = "Rule for '{$pname}' updated successfully to '" . ucfirst($req) . "'.";
                cv_log_audit(0, 'product_rule_saved', $adminId, "product_id={$pid}, req={$req}");
            } else {
                $errorMsg = 'Please select a valid product.';
            }
        }

        // 2. Add / Update Category (Bulk) Rule
        elseif (isset($_POST['add_category_rule'])) {
            $gid = (int) ($_POST['category_id'] ?? 0);
            $req = in_array($_POST['requirement'] ?? '', ['required', 'optional', 'not_required']) ? $_POST['requirement'] : 'required';
            $selectedPids = $_POST['category_product_ids'] ?? [];

            if ($gid > 0) {
                $gname = Capsule::table('tblproductgroups')->where('id', $gid)->value('name') ?: 'Category #' . $gid;
                
                // If specific products in category were checked
                if (!empty($selectedPids) && is_array($selectedPids)) {
                    $count = 0;
                    foreach ($selectedPids as $pid) {
                        $pid = (int) $pid;
                        if ($pid > 0) {
                            Capsule::table('mod_cv_product_rules')->updateOrInsert(
                                ['product_id' => $pid],
                                ['requirement' => $req, 'updated_at' => date('Y-m-d H:i:s')]
                            );
                            $count++;
                        }
                    }
                    $successMsg = "Successfully updated {$count} product(s) in category '{$gname}' to '" . ucfirst($req) . "'.";
                    cv_log_audit(0, 'category_product_rules_saved', $adminId, "gid={$gid}, count={$count}, req={$req}");
                } else {
                    // Apply to ALL products in this category
                    $productsInGroup = Capsule::table('tblproducts')->where('gid', $gid)->pluck('id')->toArray();
                    if (!empty($productsInGroup)) {
                        foreach ($productsInGroup as $pid) {
                            Capsule::table('mod_cv_product_rules')->updateOrInsert(
                                ['product_id' => (int) $pid],
                                ['requirement' => $req, 'updated_at' => date('Y-m-d H:i:s')]
                            );
                        }
                        $count = count($productsInGroup);
                        $successMsg = "Successfully applied '" . ucfirst($req) . "' to all {$count} product(s) in category '{$gname}'.";
                        cv_log_audit(0, 'category_product_rules_saved', $adminId, "gid={$gid}, count={$count}, req={$req}");
                    } else {
                        $errorMsg = "Category '{$gname}' does not contain any products.";
                    }
                }
            } else {
                $errorMsg = 'Please select a valid product category.';
            }
        }

        // 3. Delete All Rules in a Category
        elseif (isset($_POST['delete_category_rules'])) {
            $gid = (int) ($_POST['category_id'] ?? 0);
            if ($gid > 0) {
                $gname = Capsule::table('tblproductgroups')->where('id', $gid)->value('name') ?: 'Category #' . $gid;
                $productsInGroup = Capsule::table('tblproducts')->where('gid', $gid)->pluck('id')->toArray();
                if (!empty($productsInGroup)) {
                    $deleted = Capsule::table('mod_cv_product_rules')->whereIn('product_id', $productsInGroup)->delete();
                    $successMsg = "Removed verification rules for {$deleted} product(s) in category '{$gname}'.";
                    cv_log_audit(0, 'category_rules_deleted', $adminId, "gid={$gid}, deleted={$deleted}");
                } else {
                    $errorMsg = "No products found in this category.";
                }
            }
        }

        // 4. Inline Update Rule Requirement
        elseif (isset($_POST['update_inline_req'])) {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $req = in_array($_POST['inline_requirement'] ?? '', ['required', 'optional', 'not_required']) ? $_POST['inline_requirement'] : 'required';
            if ($ruleId > 0) {
                Capsule::table('mod_cv_product_rules')->where('id', $ruleId)->update([
                    'requirement' => $req,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $successMsg = "Product rule requirement updated to '" . ucfirst($req) . "'.";
                cv_log_audit(0, 'product_rule_inline_updated', $adminId, "rule_id={$ruleId}, req={$req}");
            }
        }

        // 5. Delete Single Rule
        elseif (isset($_POST['delete_id'])) {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            if ($delId > 0) {
                Capsule::table('mod_cv_product_rules')->where('id', $delId)->delete();
                $successMsg = 'Product rule removed successfully.';
                cv_log_audit(0, 'product_rule_deleted', $adminId, "rule_id={$delId}");
            }
        }

        // 6. Bulk Actions on Selected Rules
        elseif (isset($_POST['bulk_action']) && !empty($_POST['selected_rules'])) {
            $selectedRules = array_map('intval', (array)$_POST['selected_rules']);
            $action = $_POST['bulk_action'];

            if ($action === 'delete') {
                $count = Capsule::table('mod_cv_product_rules')->whereIn('id', $selectedRules)->delete();
                $successMsg = "Successfully removed {$count} product rule(s).";
                cv_log_audit(0, 'product_rules_bulk_deleted', $adminId, "count={$count}");
            } elseif (in_array($action, ['set_required', 'set_optional', 'set_not_required'], true)) {
                $reqMap = [
                    'set_required' => 'required',
                    'set_optional' => 'optional',
                    'set_not_required' => 'not_required',
                ];
                $newReq = $reqMap[$action];
                $count = Capsule::table('mod_cv_product_rules')->whereIn('id', $selectedRules)->update([
                    'requirement' => $newReq,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $successMsg = "Successfully updated {$count} product rule(s) to '" . ucfirst($newReq) . "'.";
                cv_log_audit(0, 'product_rules_bulk_updated', $adminId, "count={$count}, req={$newReq}");
            }
        }
    }
}

// Fetch all categories (product groups) and products
$categories = [];
try {
    $categories = Capsule::table('tblproductgroups')
        ->orderBy('order')
        ->orderBy('name')
        ->get();
} catch (\Exception $e) {}

$allProducts = [];
$productsByGroup = [];
try {
    $allProducts = Capsule::table('tblproducts')
        ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->select('tblproducts.id', 'tblproducts.gid', 'tblproducts.name as pname', 'tblproductgroups.name as gname')
        ->orderBy('tblproductgroups.name')
        ->orderBy('tblproducts.name')
        ->get();

    foreach ($allProducts as $p) {
        $gid = (int) ($p->gid ?? 0);
        $productsByGroup[$gid][] = [
            'id' => (int) $p->id,
            'name' => (string) $p->pname,
        ];
    }
} catch (\Exception $e) {}

// Fetch all configured rules
$rules = Capsule::table('mod_cv_product_rules')
    ->leftJoin('tblproducts', 'mod_cv_product_rules.product_id', '=', 'tblproducts.id')
    ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
    ->select('mod_cv_product_rules.*', 'tblproducts.gid', 'tblproducts.name as pname', 'tblproductgroups.name as gname')
    ->orderBy('tblproductgroups.name')
    ->orderBy('tblproducts.name')
    ->get();

$requiredCount = $rules->where('requirement', 'required')->count();
$optionalCount = $rules->where('requirement', 'optional')->count();
$notRequiredCount = $rules->where('requirement', 'not_required')->count();
$totalRules = $rules->count();

cv_admin_header('product-rules', 'Product & Category Rules', 'Configure which products or product categories require mandatory KYC identity verification before checkout.');

?>

<style>
.cv-rules-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    margin-bottom: 20px;
    overflow: hidden;
}
.cv-pill-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.cv-pill-tab:hover {
    color: #1e293b;
    background: #e2e8f0;
}
.cv-pill-tab.active {
    color: #ffffff;
    background: #2563eb;
}
.cv-prod-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 13px;
    color: #334155;
    transition: background 0.1s ease;
}
.cv-prod-checkbox-item:hover {
    background: #f8fafc;
}
</style>

<!-- Stat Cards -->
<div class="row" style="margin-bottom: 20px;">
    <div class="col-md-3 col-sm-6" style="margin-bottom: 10px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Active Rules</div>
            <div style="font-size: 24px; font-weight: 700; color: #1e293b; margin-top: 4px;">
                <?php echo $totalRules; ?> <span style="font-size: 13px; color: #94a3b8; font-weight: 400;">/ <?php echo count($allProducts); ?> Products</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6" style="margin-bottom: 10px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="font-size: 12px; font-weight: 600; color: #dc2626; text-transform: uppercase;">Mandatory (Required)</div>
            <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 4px;">
                <?php echo $requiredCount; ?> <span style="font-size: 13px; color: #64748b; font-weight: 400;">Blocks Checkout</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6" style="margin-bottom: 10px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="font-size: 12px; font-weight: 600; color: #0284c7; text-transform: uppercase;">Optional Prompt</div>
            <div style="font-size: 24px; font-weight: 700; color: #0284c7; margin-top: 4px;">
                <?php echo $optionalCount; ?> <span style="font-size: 13px; color: #64748b; font-weight: 400;">Prompts Only</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6" style="margin-bottom: 10px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
            <div style="font-size: 12px; font-weight: 600; color: #16a34a; text-transform: uppercase;">Explicit Bypass</div>
            <div style="font-size: 24px; font-weight: 700; color: #16a34a; margin-top: 4px;">
                <?php echo $notRequiredCount; ?> <span style="font-size: 13px; color: #64748b; font-weight: 400;">No KYC Required</span>
            </div>
        </div>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success alert-dismissible" style="border-radius: 6px; margin-bottom: 20px;">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger alert-dismissible" style="border-radius: 6px; margin-bottom: 20px;">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="fa fa-times-circle"></i> <?php echo htmlspecialchars($errorMsg); ?>
    </div>
<?php endif; ?>

<div class="row">
    <!-- LEFT COLUMN: Add / Update Rules (Category Bulk vs Single Product) -->
    <div class="col-md-5">
        <div class="cv-rules-card" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">
                    <i class="fa fa-plus-circle text-primary"></i> Add / Update Rules
                </h4>
            </div>

            <!-- Mode Switcher Pills -->
            <div style="display: flex; gap: 8px; margin-bottom: 20px;">
                <div class="cv-pill-tab active" id="tabBtnCategory" onclick="cvSwitchRuleMode('category')">
                    <i class="fa fa-folder-open"></i> By Category (Bulk)
                </div>
                <div class="cv-pill-tab" id="tabBtnProduct" onclick="cvSwitchRuleMode('product')">
                    <i class="fa fa-cube"></i> By Single Product
                </div>
            </div>

            <!-- FORM 1: CATEGORY BULK RULE -->
            <div id="ruleModeCategory">
                <form method="post" id="formCategoryRule">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="add_category_rule" value="1">

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label style="font-size: 13px; font-weight: 600; color: #334155;">
                            Select Product Category <span style="color: #ef4444;">*</span>:
                        </label>
                        <select name="category_id" id="cv_cat_select" class="form-control" required onchange="cvHandleCategoryChange(this.value)">
                            <option value="">-- Choose a Category / Group --</option>
                            <?php foreach ($categories as $cat): 
                                $catProds = $productsByGroup[$cat->id] ?? [];
                                $pCount = count($catProds);
                            ?>
                                <option value="<?php echo (int) $cat->id; ?>">
                                    <?php echo htmlspecialchars($cat->name); ?> (<?php echo $pCount; ?> products)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                            Selecting a category allows applying verification rules across all products inside it.
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label style="font-size: 13px; font-weight: 600; color: #334155;">Verification Requirement:</label>
                        <select name="requirement" class="form-control">
                            <option value="required">Required (Block checkout if not verified) [Strict]</option>
                            <option value="optional">Optional (Prompt client, but allow checkout)</option>
                            <option value="not_required">Not Required (Explicitly bypass KYC)</option>
                        </select>
                    </div>

                    <!-- Interactive Products Preview / Filter in Category -->
                    <div id="catProductsBox" style="display: none; margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-size: 12px; font-weight: 700; color: #1e293b;">
                                Products in Category (<span id="catProdCount">0</span>):
                            </span>
                            <a href="javascript:void(0);" onclick="cvToggleAllCatProducts();" style="font-size: 11px; font-weight: 600; color: #2563eb;" id="catToggleAllBtn">
                                Select All
                            </a>
                        </div>
                        <div id="catProductsList" style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px;">
                            <!-- Dynamically populated via JS -->
                        </div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 8px;">
                            <i class="fa fa-info-circle"></i> Leave all checked to apply to the entire category, or uncheck individual items to exclude them.
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 8px 18px;">
                            <i class="fa fa-check"></i> Apply Category Rule
                        </button>
                        <button type="button" class="btn btn-default btn-sm" onclick="cvDeleteCategoryRules();" style="color: #dc2626; font-weight: 600;">
                            <i class="fa fa-trash-o"></i> Clear Category Rules
                        </button>
                    </div>
                </form>

                <form method="post" id="formDeleteCategoryRules" style="display: none;">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="delete_category_rules" value="1">
                    <input type="hidden" name="category_id" id="delete_category_id_input" value="">
                </form>
            </div>

            <!-- FORM 2: SINGLE PRODUCT RULE -->
            <div id="ruleModeProduct" style="display: none;">
                <form method="post">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="add_single_product" value="1">

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label style="font-size: 13px; font-weight: 600; color: #334155;">
                            Select Product <span style="color: #ef4444;">*</span>:
                        </label>
                        <select name="product_id" class="form-control" required style="width: 100%;">
                            <option value="">-- Choose a Product --</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?php echo (int) $p->id; ?>">
                                    <?php echo htmlspecialchars(($p->gname ? $p->gname . ' &rarr; ' : '') . $p->pname . ' (#' . $p->id . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="font-size: 13px; font-weight: 600; color: #334155;">Verification Requirement:</label>
                        <select name="requirement" class="form-control">
                            <option value="required">Required (Block checkout if not verified)</option>
                            <option value="optional">Optional (Prompt client, but allow checkout)</option>
                            <option value="not_required">Not Required (Explicitly bypass KYC)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 8px 20px;">
                        <i class="fa fa-save"></i> Save Product Rule
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Configured Product Rules Table with Filters & Bulk Actions -->
    <div class="col-md-7">
        <form method="post" id="cvBulkRulesForm">
            <?php echo Csrf::field(); ?>
            <input type="hidden" name="bulk_action" id="bulkActionInput" value="">

            <div class="cv-rules-card">
                <!-- Table Header Bar with Search and Category Filter -->
                <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">
                            Configured Rules (<span id="visibleRuleCount"><?php echo count($rules); ?></span>)
                        </h4>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <!-- Category Filter -->
                        <select id="ruleCategoryFilter" class="form-control input-sm" style="width: 170px; font-size: 12px;" onchange="cvFilterRulesTable()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars(strtolower($cat->name)); ?>">
                                    <?php echo htmlspecialchars($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Search Input -->
                        <input type="text" id="ruleSearchInput" class="form-control input-sm" placeholder="Search product..." style="width: 150px; font-size: 12px;" onkeyup="cvFilterRulesTable()">
                    </div>
                </div>

                <!-- Bulk Action Toolbar -->
                <div style="padding: 8px 20px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">Bulk Actions:</span>
                        <button type="button" class="btn btn-default btn-xs" onclick="cvSubmitBulkAction('set_required')" title="Set selected to Required">
                            <i class="fa fa-lock text-danger"></i> Set Required
                        </button>
                        <button type="button" class="btn btn-default btn-xs" onclick="cvSubmitBulkAction('set_optional')" title="Set selected to Optional">
                            <i class="fa fa-info-circle text-info"></i> Set Optional
                        </button>
                        <button type="button" class="btn btn-default btn-xs" onclick="cvSubmitBulkAction('set_not_required')" title="Set selected to Not Required">
                            <i class="fa fa-unlock text-success"></i> Set Bypass
                        </button>
                        <button type="button" class="btn btn-danger btn-xs" onclick="cvSubmitBulkAction('delete')" title="Delete selected rules">
                            <i class="fa fa-trash"></i> Delete Selected
                        </button>
                    </div>
                </div>

                <div class="table-responsive" style="margin: 0;">
                    <table class="table table-hover" id="rulesTable" style="margin: 0;">
                        <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                            <tr>
                                <th style="width: 36px; text-align: center; vertical-align: middle;">
                                    <input type="checkbox" id="selectAllRules" onclick="cvToggleSelectAllRules(this.checked)">
                                </th>
                                <th style="font-size: 12px; color: #64748b;">Product &amp; Category</th>
                                <th style="font-size: 12px; color: #64748b; width: 170px;">Requirement</th>
                                <th style="font-size: 12px; color: #64748b; text-align: right; width: 60px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rules->isEmpty()): ?>
                                <tr id="noRulesRow">
                                    <td colspan="4" style="text-align: center; padding: 36px 20px; color: #94a3b8;">
                                        <i class="fa fa-shield" style="font-size: 32px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                        No product-specific rules configured yet.<br>
                                        <span style="font-size: 12px;">Global module settings will apply to all products during checkout.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rules as $r):
                                    $catNameLower = strtolower($r->gname ?: 'uncategorized');
                                    $prodNameLower = strtolower($r->pname ?: '');
                                ?>
                                    <tr class="cv-rule-row" data-category="<?php echo htmlspecialchars($catNameLower); ?>" data-search="<?php echo htmlspecialchars($prodNameLower . ' ' . $catNameLower . ' ' . $r->product_id); ?>">
                                        <td style="text-align: center; vertical-align: middle;">
                                            <input type="checkbox" name="selected_rules[]" value="<?php echo (int) $r->id; ?>" class="cv-rule-check">
                                        </td>
                                        <td style="vertical-align: middle;">
                                            <strong style="color: #1e293b;"><?php echo htmlspecialchars($r->pname ?? ('Product #' . $r->product_id)); ?></strong>
                                            <span style="font-size: 11px; color: #94a3b8; margin-left: 4px;">(#<?php echo (int) $r->product_id; ?>)</span>
                                            <?php if (!empty($r->gname)): ?>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                                    <i class="fa fa-folder-o"></i> <?php echo htmlspecialchars($r->gname); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="vertical-align: middle;">
                                            <select class="form-control input-sm" style="font-size: 12px; font-weight: 600; border-radius: 4px;" onchange="cvInlineUpdateReq(<?php echo (int)$r->id; ?>, this.value)">
                                                <option value="required" <?php echo $r->requirement === 'required' ? 'selected' : ''; ?>>🔴 Required</option>
                                                <option value="optional" <?php echo $r->requirement === 'optional' ? 'selected' : ''; ?>>🔵 Optional</option>
                                                <option value="not_required" <?php echo $r->requirement === 'not_required' ? 'selected' : ''; ?>>⚪ Not Required</option>
                                            </select>
                                        </td>
                                        <td style="text-align: right; vertical-align: middle;">
                                            <button type="button" class="btn btn-danger btn-xs" title="Remove rule" onclick="cvSubmitSingleDelete(<?php echo (int)$r->id; ?>)">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- Hidden Forms for single actions -->
        <form method="post" id="cvSingleDeleteForm" style="display: none;">
            <?php echo Csrf::field(); ?>
            <input type="hidden" name="delete_id" id="cvDeleteIdInput" value="">
        </form>

        <form method="post" id="cvInlineUpdateForm" style="display: none;">
            <?php echo Csrf::field(); ?>
            <input type="hidden" name="update_inline_req" value="1">
            <input type="hidden" name="rule_id" id="cvInlineRuleId" value="">
            <input type="hidden" name="inline_requirement" id="cvInlineReqValue" value="">
        </form>
    </div>
</div>

<script>
var cvGroupProducts = <?php echo json_encode($productsByGroup); ?>;

function cvSwitchRuleMode(mode) {
    var catTab = document.getElementById('tabBtnCategory');
    var prodTab = document.getElementById('tabBtnProduct');
    var catPanel = document.getElementById('ruleModeCategory');
    var prodPanel = document.getElementById('ruleModeProduct');

    if (mode === 'category') {
        catTab.classList.add('active');
        prodTab.classList.remove('active');
        catPanel.style.display = 'block';
        prodPanel.style.display = 'none';
    } else {
        prodTab.classList.add('active');
        catTab.classList.remove('active');
        prodPanel.style.display = 'block';
        catPanel.style.display = 'none';
    }
}

function cvHandleCategoryChange(gid) {
    var box = document.getElementById('catProductsBox');
    var list = document.getElementById('catProductsList');
    var countSpan = document.getElementById('catProdCount');

    if (!gid || !cvGroupProducts[gid] || cvGroupProducts[gid].length === 0) {
        box.style.display = 'none';
        list.innerHTML = '';
        countSpan.textContent = '0';
        return;
    }

    var prods = cvGroupProducts[gid];
    countSpan.textContent = prods.length;
    list.innerHTML = '';

    prods.forEach(function(p) {
        var div = document.createElement('label');
        div.className = 'cv-prod-checkbox-item';
        div.innerHTML = '<input type="checkbox" name="category_product_ids[]" value="' + p.id + '" checked class="cv-cat-prod-check"> <span>' + p.name + ' <span style="color:#94a3b8; font-size:11px;">(#' + p.id + ')</span></span>';
        list.appendChild(div);
    });

    box.style.display = 'block';
}

function cvToggleAllCatProducts() {
    var checks = document.querySelectorAll('.cv-cat-prod-check');
    var allChecked = Array.from(checks).every(function(c) { return c.checked; });
    var btn = document.getElementById('catToggleAllBtn');

    checks.forEach(function(c) {
        c.checked = !allChecked;
    });

    btn.textContent = allChecked ? 'Select All' : 'Deselect All';
}

function cvDeleteCategoryRules() {
    var sel = document.getElementById('cv_cat_select');
    var gid = sel.value;
    if (!gid) {
        alert('Please select a category first.');
        return;
    }
    var catName = sel.options[sel.selectedIndex].text;
    if (confirm('Remove all verification rules for category: ' + catName + '?')) {
        document.getElementById('delete_category_id_input').value = gid;
        document.getElementById('formDeleteCategoryRules').submit();
    }
}

function cvFilterRulesTable() {
    var catFilter = document.getElementById('ruleCategoryFilter').value.toLowerCase();
    var search = document.getElementById('ruleSearchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.cv-rule-row');
    var visible = 0;

    rows.forEach(function(r) {
        var rCat = r.getAttribute('data-category') || '';
        var rSearch = r.getAttribute('data-search') || '';

        var matchesCat = !catFilter || rCat.includes(catFilter);
        var matchesSearch = !search || rSearch.includes(search);

        if (matchesCat && matchesSearch) {
            r.style.display = '';
            visible++;
        } else {
            r.style.display = 'none';
        }
    });

    var countSpan = document.getElementById('visibleRuleCount');
    if (countSpan) countSpan.textContent = visible;
}

function cvToggleSelectAllRules(checked) {
    document.querySelectorAll('.cv-rule-check').forEach(function(c) {
        var row = c.closest('tr');
        if (row && row.style.display !== 'none') {
            c.checked = checked;
        }
    });
}

function cvSubmitBulkAction(action) {
    var selected = document.querySelectorAll('.cv-rule-check:checked');
    if (selected.length === 0) {
        alert('Please select at least one rule checkbox.');
        return;
    }

    if (action === 'delete' && !confirm('Permanently delete ' + selected.length + ' selected rule(s)?')) {
        return;
    }

    document.getElementById('bulkActionInput').value = action;
    document.getElementById('cvBulkRulesForm').submit();
}

function cvSubmitSingleDelete(id) {
    if (confirm('Delete this product rule?')) {
        document.getElementById('cvDeleteIdInput').value = id;
        document.getElementById('cvSingleDeleteForm').submit();
    }
}

function cvInlineUpdateReq(ruleId, reqValue) {
    document.getElementById('cvInlineRuleId').value = ruleId;
    document.getElementById('cvInlineReqValue').value = reqValue;
    document.getElementById('cvInlineUpdateForm').submit();
}
</script>


