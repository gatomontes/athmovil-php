<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ATH Móvil · Checkout Lab</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<div class="shell">
    <header class="topbar"><a class="brand" href="/"><span class="brand-icon">A</span> ATH Móvil <span class="brand-sub">/ Checkout Lab</span></a><span class="pill">LOCAL SIMULATION</span></header>
    <section class="intro"><div class="eyebrow">A COMPLETE CHECKOUT, WITHOUT MOVING MONEY</div><h1>From cart to receipt.</h1><p>Submit your items. Play the customer. Complete a payment and try a refund.</p></section>
    <div class="notice">Simulation only · No business credentials required · No requests are sent to ATH Móvil</div>
    <?php if ($flash !== null): ?><div role="status" class="flash <?= escape($flash[0]) ?>"><?= escape($flash[1]) ?></div><?php endif; ?>
    <main class="layout">
        <section class="panel cart">
            <div class="panel-heading"><div><span class="eyebrow">01 / BUILD THE REQUEST</span><h2>Your cart</h2></div><span class="subtle">USD</span></div>
            <form method="post" action="/">
                <input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="create">
                <p class="hint">Edit up to three lines. Leave an item name blank to omit that line. Amounts are submitted exactly as entered.</p>
                <?php for ($i = 0; $i < 3; ++$i): $item = $draft['items'][$i] ?? defaults()['items'][2]; ?>
                <fieldset class="item"><legend>Item <?= $i + 1 ?></legend>
                    <div class="fields two"><label>Name<input name="items[<?= $i ?>][name]" value="<?= escape($item['name']) ?>" maxlength="120" placeholder="Item name"></label><label>Description<input name="items[<?= $i ?>][description]" value="<?= escape($item['description']) ?>" maxlength="240" placeholder="Description"></label></div>
                    <div class="fields three"><label>Quantity<input name="items[<?= $i ?>][quantity]" value="<?= escape($item['quantity']) ?>" type="number" min="1" max="9999"></label><label>Unit price<input name="items[<?= $i ?>][price]" value="<?= escape($item['price']) ?>" inputmode="decimal" placeholder="0.00"></label><label>Item tax<input name="items[<?= $i ?>][tax]" value="<?= escape($item['tax']) ?>" inputmode="decimal" placeholder="Optional"></label></div>
                    <label>Item metadata<input name="items[<?= $i ?>][metadata]" value="<?= escape($item['metadata']) ?>" maxlength="40" placeholder="SKU or item reference"></label>
                </fieldset>
                <?php endfor; ?>
                <div class="fields three totals"><label>Subtotal<input name="subtotal" value="<?= escape($draft['subtotal']) ?>" inputmode="decimal"></label><label>Tax<input name="tax" value="<?= escape($draft['tax']) ?>" inputmode="decimal"></label><label>Total<input name="total" value="<?= escape($draft['total']) ?>" inputmode="decimal" required></label></div>
                <div class="fields two"><label>Payment metadata 1<input name="metadata1" value="<?= escape($draft['metadata1']) ?>" maxlength="40" placeholder="Order ID"></label><label>Payment metadata 2<input name="metadata2" value="<?= escape($draft['metadata2']) ?>" maxlength="40" placeholder="Store ID"></label></div>
                <div class="fields two"><label>Submitted phone<input name="phone" value="<?= escape($draft['phone']) ?>" pattern="[0-9]{10}" inputmode="tel" required></label><label>Expires after (seconds)<input name="timeout" value="<?= escape($draft['timeout']) ?>" type="number" min="120" max="600" required></label></div>
                <button class="primary wide" type="submit">Create simulated payment <span aria-hidden="true">→</span></button>
            </form>
        </section>
        <div class="right-column">
            <section class="panel transaction">
                <div class="panel-heading"><div><span class="eyebrow">02 / COMPLETE THE FLOW</span><h2>Payment desk</h2></div><?php if ($status !== null): ?><span id="payment-status" class="status <?= escape(strtolower($status)) ?>"><?= escape($status) ?></span><?php endif; ?></div>
                <?php if ($result === null): ?>
                    <div class="empty"><div class="empty-icon">↗</div><h3>Your first payment starts here.</h3><p>Create a request using the cart. Its status and available actions will appear here.</p></div>
                <?php else: ?>
                    <div class="payment-amount">$<?= escape($result->total()) ?></div><p class="hint">Payment reference</p><code id="payment-id" class="reference"><?= escape($id) ?></code>
                    <div class="customer"><span class="avatar">TP</span><div><strong>Test Payer</strong><span>Simulated customer · test.payer@example.com</span></div></div>
                    <p class="hint">This fictional identity is used in the simulator’s customer fields. The submitted phone stays in the captured request.</p>
                    <?php if (in_array($status, ['OPEN', 'CONFIRM'], true)): ?>
                        <form method="post" action="/" class="action-form"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="id" value="<?= escape($id) ?>">
                        <?php if ($status === 'OPEN'): ?><button class="primary wide" name="action" value="confirm">Confirm as Test Payer</button><?php endif; ?>
                        <?php if ($status === 'CONFIRM'): ?><label>Authorization scenario<select name="scenario"><option value="success">Successful payment</option><option value="reject">Provider rejection</option><option value="timeout_before">Timeout before processing</option><option value="timeout_after">Payment completes, response is lost</option></select></label><button class="primary wide" name="action" value="authorize">Authorize payment</button><?php endif; ?>
                        <div class="button-row"><button class="secondary" name="action" value="cancel">Cancel payment</button><button class="secondary" name="action" value="expire">Simulate expiry</button></div>
                        </form>
                    <?php endif; ?>
                    <?php if ($status === 'OPEN'): ?><details><summary>Change the submitted phone</summary><form method="post" action="/" class="action-form"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="id" value="<?= escape($id) ?>"><input type="hidden" name="action" value="phone"><label>New phone<input name="phone" value="7875550101" pattern="[0-9]{10}" required></label><button class="secondary" type="submit">Update phone</button></form></details><?php endif; ?>
                    <?php if ($status === 'COMPLETED'): ?>
                        <div id="reconciliation" class="flash <?= $reconciled ? 'success' : 'error' ?>"><?= $reconciled ? 'Receipt matched to the stored demo order.' : 'Order reconciliation failed. Do not mark this order paid.' ?></div>
                        <div class="receipt"><h3>Payment receipt</h3><dl><dt>Receipt reference</dt><dd id="receipt-reference"><?= escape($result->referenceNumber()) ?></dd><dt>Metadata 1</dt><dd><?= escape($result->metadata1()) ?></dd><dt>Metadata 2</dt><dd><?= escape($result->metadata2()) ?></dd><dt>Total refunded</dt><dd id="refunded-amount">$<?= escape(number_format((float) $data['totalRefundedAmount'], 2, '.', '')) ?></dd></dl></div>
                        <form method="post" action="/" class="action-form refund"><h3>Issue a simulated refund</h3><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="id" value="<?= escape($id) ?>"><input type="hidden" name="action" value="refund"><div class="fields two"><label>Refund amount<input name="amount" value="5.00" inputmode="decimal" required></label><label>Message<input name="message" value="Demo refund" maxlength="50"></label></div><label>Refund scenario<select name="scenario"><option value="success">Successful refund</option><option value="reject">Provider rejection</option><option value="timeout_before">Timeout before processing</option><option value="timeout_after">Refund completes, response is lost</option></select></label><button class="secondary wide" type="submit">Submit refund</button></form>
                        <?php foreach ($refunds as $refund): ?><div class="refund-receipt"><strong>$<?= escape(number_format((float) $refund['refundedAmount'], 2, '.', '')) ?> refunded · <?= escape($refund['name']) ?></strong><span><?= escape($refund['referenceNumber']) ?></span><span><?= escape($refund['phoneNumber']) ?> · <?= escape($refund['email']) ?></span></div><?php endforeach; ?>
                    <?php endif; ?>
                    <details><summary>Returned items &amp; metadata</summary><pre><?= escape(json_encode(['items' => $result->items(), 'metadata1' => $result->metadata1(), 'metadata2' => $result->metadata2()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
                    <a class="text-link" href="/?id=<?= escape(rawurlencode($id)) ?>">Refresh payment status ↻</a>
                <?php endif; ?>
            </section>
            <?php if ($orders !== []): ?><section class="panel"><span class="eyebrow">THIS BROWSER SESSION</span><h2>Your requests</h2><nav class="order-list" aria-label="Demo payment requests"><?php foreach ($orders as $orderId => $saved): ?><a href="/?id=<?= escape(rawurlencode($orderId)) ?>" <?= $orderId === $id ? 'aria-current="page"' : '' ?>><strong><?= escape($saved['metadata1'] !== '' ? $saved['metadata1'] : 'Untitled order') ?></strong><span>$<?= escape($saved['expectedTotal']) ?> · <?= escape(substr($orderId, -8)) ?></span></a><?php endforeach; ?></nav></section><?php endif; ?>
            <section class="panel captures"><span class="eyebrow">03 / INSPECT THE EXCHANGE</span><h2>Request &amp; response capture</h2><p class="hint">Includes submitted items, metadata, state changes, and lost-response diagnostics. Credential fields are redacted; your entered data remains.</p><details><summary>View <?= count($history) ?> captured events</summary><pre id="capture-history"><?= escape($captureJson) ?></pre></details><div class="button-row"><a class="secondary button" href="/?export=1">Download JSON</a><form method="post" action="/"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><input type="hidden" name="action" value="reset"><button class="text-button" type="submit">Reset this session</button></form></div></section>
        </div>
    </main>
    <footer>ATH Móvil PHP · Unofficial developer example. Local simulation is not provider acceptance.</footer>
</div>
</body>
</html>
