const fs = require('fs');
const assert = require('assert');

const cart = fs.readFileSync('includes/public_cart.php', 'utf8');
assert(cart.includes('data-card-enabled'));
assert(cart.includes('data-culqi-public-key'));
assert(!cart.includes('CULQI_PRIVATE_KEY'));

const app = fs.readFileSync('assets/js/app.js', 'utf8');
assert(app.includes('tarjeta'));
assert(app.includes('culqi_charge.php'));
console.log('culqi_checkout_ui_test OK');
