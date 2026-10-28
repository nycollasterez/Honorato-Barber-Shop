const fs = require('fs');
const code = fs.readFileSync('assets/index-CokOjzJx.js', 'utf8');

const s = code.indexOf('id:"admin-dashboard-view"');
console.log(code.substring(s, s + 1000));
