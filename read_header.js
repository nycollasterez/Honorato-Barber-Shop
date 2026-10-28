const fs = require('fs');
const code = fs.readFileSync('assets/index-CokOjzJx.js', 'utf8');

const start = code.indexOf('function X_(');
const end = code.indexOf('function Q_(', start);
console.log(code.substring(start, end));
