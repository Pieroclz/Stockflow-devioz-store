const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const read = (file) => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');

test('recommendation candidates are bounded and retain fields needed for final visibility validation', () => {
    const source = read('includes/ai_recommendations.php');
    const start = source.indexOf('function recommendation_candidates(');
    const end = source.indexOf('function recommendation_candidates_for_ids(', start);
    const candidateFunction = source.slice(start, end);

    assert.ok(start >= 0 && end > start, 'candidate query function must exist');
    assert.match(candidateFunction, /master_product_visibility_sql\('p'\)/);
    assert.match(candidateFunction, /p\.catalog_scope[\s\S]*p\.active[\s\S]*p\.sale_enabled[\s\S]*p\.restricted/);
    assert.match(candidateFunction, /'LIMIT '[\s\S]*RECOMMENDATION_CANDIDATE_LIMIT/);
    assert.match(source, /RECOMMENDATION_CANDIDATE_LIMIT\s*=\s*100/);
    assert.match(candidateFunction, /recommendation_candidate_is_sellable\(/);
});

test('both AI prompts are built from explicit public-field allowlists', () => {
    const recommendations = read('includes/ai_recommendations.php');
    const shoppingAi = read('includes/shopping_ai.php');

    assert.match(recommendations, /function recommendation_prompt_catalog\(/);
    assert.match(shoppingAi, /function shopping_ai_prompt_catalog\(/);
    for (const source of [recommendations, shoppingAi]) {
        const promptStart = source.indexOf('function ', source.indexOf('_prompt_catalog'));
        const nextFunction = source.indexOf('\nfunction ', promptStart + 10);
        const promptFunction = source.slice(promptStart, nextFunction < 0 ? undefined : nextFunction);
        assert.doesNotMatch(promptFunction, /cost_price|supplier_price|api_key|ollama_token|catalog_scope|sale_enabled|restricted|\bSQL\b/i);
    }
});

test('recommendation confirmation is one-time and reaches the metric only after the cart guard', () => {
    const api = read('api/recomendaciones.php');
    const app = read('assets/js/app.js');
    const handlerStart = app.indexOf("$('#confirmAiProposal')?.addEventListener");
    const handlerEnd = app.indexOf('},{once:true});', handlerStart);
    const handler = app.slice(handlerStart, handlerEnd);

    assert.match(api, /recommendation_confirmation_consume\(/);
    assert.match(api, /confirmed_additions=confirmed_additions\+1/);
    assert.ok(handlerStart >= 0 && handlerEnd > handlerStart, 'cart confirmation handler must exist');
    assert.ok(handler.indexOf('confirm(') >= 0, 'cart mutation must require a user confirmation');
    assert.equal(handler.includes('virtual.click()'), false, 'cart expansion must not synthesize one DOM click per unit');
    assert.match(handler, /addRecommendationProposal\(result\)/);
});

test('model quantities are bounded and inventory is refreshed after model selection', () => {
    const source = read('includes/ai_recommendations.php');
    const api = read('api/recomendaciones.php');
    assert.match(source, /RECOMMENDATION_MAX_QUANTITY\s*=\s*99/);
    assert.match(source, /RECOMMENDATION_MAX_TOTAL_QUANTITY\s*=\s*99/);
    assert.match(source, /function recommendation_candidates_for_ids\(/);
    assert.match(api, /recommendation_candidates_for_ids\(db\(\),\$request/);
});

test('legacy assistant proposals use the shared expiring confirmation path', () => {
    const assistant = read('asistente_compras.php');
    const chat = read('assets/js/shopping_chat.js');
    assert.match(assistant, /recommendation_confirmation_create\(/);
    assert.match(assistant, /devioz-ai-confirmation-v1/);
    assert.match(chat, /devioz-ai-confirmation-v1/);
    assert.match(chat, /confirmation:data\.confirmation|data\.confirmation/);
});

test('legacy assistant queries no more than sixty visible master candidates', () => {
    const source = read('includes/shopping_sources.php');
    const start = source.indexOf('function shopping_load_catalog');
    const end = source.indexOf('function shopping_validate_import', start);
    const loader = source.slice(start, end);

    assert.match(loader, /master_product_visibility_sql\('p'\)/);
    assert.match(loader, /p\.active[\s\S]*p\.sale_enabled[\s\S]*p\.restricted/);
    assert.match(loader, /LIMIT 60/);
});

test('legacy planner has a valid slice and refreshes fallback candidates after AI', () => {
    const planner = read('includes/shopping_agent.php');
    assert.ok(planner.includes('))), 0, 60);'), 'planner slice must include offset and length');
    assert.match(planner, /if \(\$selection\['ids'\] === null\)[\s\S]*shopping_load_catalog\(\$pdo, 0, \$category, \$config, \$request\)/);
    assert.match(planner, /shopping_load_catalog_by_ids\(\$pdo, array_map\('intval', \$selection\['ids'\]\)/);
});

test('legacy cart replacement requires a bounded proposal token', () => {
    const assistant = read('asistente_compras.php');
    const chat = read('assets/js/shopping_chat.js');
    const app = read('assets/js/app.js');
    assert.match(assistant, /line_limit.*RECOMMENDATION_MAX_LINES/);
    assert.match(assistant, /planCanMutate=is_string\(\$confirmation\)/);
    assert.match(assistant, /pending\.cartItems/);
    assert.doesNotMatch(assistant, /localStorage\.setItem\('devioz-store-lista-v2'/);
    assert.match(chat, /data\.confirmation.*plan\.items\.length<=12/);
    assert.match(chat, /cartItems:items/);
    assert.match(app, /revalidateShoppingList\(false\)/);
    assert.match(app, /pending\.cartItems/);
});

test('confirmation stores distinct line and per-product quantity limits', () => {
    const source = read('includes/ai_recommendations.php');
    const api = read('api/recomendaciones.php');
    assert.match(source, /'line_limit'/);
    assert.match(source, /'quantity_limit'/);
    assert.match(api, /\$lineLimit/);
    assert.match(api, /\$quantityLimit/);
});
