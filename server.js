const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = 3000;
const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.svg': 'image/svg+xml'
};

// Initial in-memory mock database for local preview
const db = {
  barbers: [
    {
      id: 'barber-1',
      name: 'Erick Machado (Barbeiro Master)',
      avatar: 'https://images.unsplash.com/photo-1503951914875-452162b0f3f1?w=400',
      bio: 'Especialista em visagismo masculino, cortes clássicos e navalhoterapia.',
      email: 'erick@honoratobarber.com',
      phone: '(34) 99999-1111',
      active: true,
      agendaActive: true,
      workDays: [1, 2, 3, 4, 5, 6],
      workStart: '09:00',
      workEnd: '19:00',
      lunchStart: '12:00',
      lunchEnd: '13:00',
      services: ['s1', 's2', 's3', 's4'],
      commissionPercentage: 50,
      barbearia_id: 'default'
    },
    {
      id: 'barber-2',
      name: 'Carlos Oliveira',
      avatar: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=400',
      bio: 'Mestre em degradê, freestyle e barboterapia.',
      email: 'carlos@honoratobarber.com',
      phone: '(34) 99999-2222',
      active: true,
      agendaActive: true,
      workDays: [1, 2, 3, 4, 5],
      workStart: '10:00',
      workEnd: '20:00',
      lunchStart: '13:00',
      lunchEnd: '14:00',
      services: ['s1', 's2', 's4'],
      commissionPercentage: 50,
      barbearia_id: 'default'
    }
  ],
  serviceCatalog: [
    { id: 's1', category: 'Cortes', name: 'Corte Masculino Premium', description: 'Visagismo, lavagem relaxante e finalização com pomada.', duration: '30-40 min', price: 'R$ 45,00', popular: true, barbearia_id: 'default' },
    { id: 's2', category: 'Barba', name: 'Barba & Navalhoterapia', description: 'Toalhas quentes aromáticas, esfoliação facial e alinhamento.', duration: '30 min', price: 'R$ 35,00', popular: false, barbearia_id: 'default' },
    { id: 's3', category: 'Combos', name: 'Combo Royal (Corte + Barba)', description: 'Experiência completa de corte visagista + barba premium.', duration: '60 min', price: 'R$ 75,00', popular: true, barbearia_id: 'default' },
    { id: 's4', category: 'Cortes', name: 'Degradê Navalhado / Fade', description: 'Transição suave com acabamento na navalha.', duration: '40 min', price: 'R$ 50,00', popular: false, barbearia_id: 'default' }
  ],
  gallery: [
    { id: 'gal-1', title: 'Degradê Americano / Low Fade', barber: 'Erick Machado', image: 'https://images.unsplash.com/photo-1599566150163-29194dcaad36?auto=format&fit=crop&q=80&w=600', description: 'Transição suave na navalha e acabamento milimétrico.' },
    { id: 'gal-2', title: 'Barba Terapia Alinhada', barber: 'Gabriel Silva', image: 'https://images.unsplash.com/photo-1621605815971-fbc98d665033?auto=format&fit=crop&q=80&w=600', description: 'Toalhas quentes, visagismo e óleo hidratante artesanal.' },
    { id: 'gal-3', title: 'Corte Clássico Pompadour', barber: 'Erick Machado', image: 'https://images.unsplash.com/photo-1503951914875-452162b0f3f1?auto=format&fit=crop&q=80&w=600', description: 'Volume estruturado com fixação premium fosca.' },
    { id: 'gal-4', title: 'Mid Fade + Freestyle', barber: 'Gabriel Silva', image: 'https://images.unsplash.com/photo-1585747860715-2ba37e788b70?auto=format&fit=crop&q=80&w=600', description: 'Design personalizado na lâmina e textura no topo.' }
  ],
  bookings: [
    {
      id: 'book-101',
      barberId: 'barber-1',
      barberName: 'Erick Machado (Barbeiro Master)',
      clientId: 'client-1',
      clientName: 'Rodrigo Guimarães',
      clientPhone: '(34) 99318-4075',
      serviceId: 's3',
      serviceName: 'Combo Royal (Corte + Barba)',
      price: 75,
      date: new Date().toISOString().split('T')[0],
      time: '14:00',
      paymentMethod: 'presencial',
      paymentStatus: 'Pagamento Presencial Pendente',
      status: 'Agendado',
      barbearia_id: 'default'
    },
    {
      id: 'book-102',
      barberId: 'barber-1',
      barberName: 'Erick Machado (Barbeiro Master)',
      clientId: 'client-2',
      clientName: 'Fernando Costa',
      clientPhone: '(34) 99888-3333',
      serviceId: 's1',
      serviceName: 'Corte Masculino Premium',
      price: 45,
      date: new Date().toISOString().split('T')[0],
      time: '15:30',
      paymentMethod: 'presencial',
      paymentStatus: 'Pagamento Presencial Pendente',
      status: 'Agendado',
      barbearia_id: 'default'
    },
    {
      id: 'book-103',
      barberId: 'barber-1',
      barberName: 'Erick Machado (Barbeiro Master)',
      clientId: 'client-3',
      clientName: 'Gustavo Henrique',
      clientPhone: '(34) 99777-4444',
      serviceId: 's2',
      serviceName: 'Barba & Navalhoterapia',
      price: 35,
      date: new Date().toISOString().split('T')[0],
      time: '10:00',
      paymentMethod: 'presencial',
      paymentStatus: 'Pago',
      status: 'Concluído',
      barbearia_id: 'default'
    }
  ],
  clients: [
    { id: 'client-1', name: 'Rodrigo Guimarães', email: 'rodrigo@gmail.com', phone: '(34) 99318-4075', createdAt: '2026-10-01T10:00:00Z', barbearia_id: 'default' }
  ],
  products: [],
  drinks: [],
  subscriptionPlans: [],
  paymentSettings: { pixKey: '34993184075', pixKeyType: 'phone', beneficiaryName: 'Honorato Barber', bankName: 'Nubank', city: 'Ituiutaba' }
};

let currentSession = null;

function handleRequest(req, res) {
  // Common CORS and Cache Headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token, Authorization');
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const reqPath = parsedUrl.pathname;

  console.log(`[HTTP] ${req.method} ${req.url}`);

  // Auto-login shortcuts via URL query param:
  // e.g. http://localhost:3000/?as=barber
  if (parsedUrl.searchParams.get('as') === 'barber' || parsedUrl.searchParams.get('login') === 'barber') {
    currentSession = {
      id: 'barber-1',
      role: 'barber',
      name: 'Erick Machado',
      email: 'erick@honoratobarber.com',
      phone: '(34) 99999-1111',
      barbearia_id: 'default'
    };
    res.writeHead(302, { 'Location': '/' });
    res.end();
    return;
  }

  if (parsedUrl.searchParams.get('as') === 'client') {
    currentSession = {
      id: 'client-1',
      role: 'client',
      name: 'Rodrigo Guimarães',
      email: 'rodrigo@gmail.com',
      phone: '(34) 99318-4075',
      barbearia_id: 'default'
    };
    res.writeHead(302, { 'Location': '/' });
    res.end();
    return;
  }

  if (parsedUrl.searchParams.has('logout')) {
    currentSession = null;
    res.writeHead(302, { 'Location': '/' });
    res.end();
    return;
  }

  // JSON helper
  const sendJson = (data, status = 200) => {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(data));
  };

  // Helper to read JSON body
  const readBody = (callback) => {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      try {
        const json = body ? JSON.parse(body) : {};
        callback(json);
      } catch (e) {
        callback({});
      }
    });
  };

  // ----------------------------------------------------
  // API ROUTING
  // ----------------------------------------------------
  if (reqPath.startsWith('/api/')) {
    const route = reqPath.replace(/^\/api\/?/, '');

    if (route === 'csrf-token') {
      return sendJson({ csrfToken: 'local-dev-token' });
    }

    if (route === 'session') {
      if (currentSession) {
        return sendJson({
          authenticated: true,
          session: currentSession
        });
      }
      return sendJson({
        authenticated: false,
        session: null
      });
    }

    if (route === 'login' && req.method === 'POST') {
      return readBody(input => {
        const identifier = (input.phone || input.email || '').trim();
        const cleanPhone = identifier.replace(/\D/g, '');
        let role = input.role;

        if (!role || role === 'unified') {
          // Detecção automática de perfil
          if (identifier.toLowerCase().includes('admin') || identifier.toLowerCase().includes('gerente') || cleanPhone.endsWith('0000')) {
            role = 'admin';
          } else if (identifier.toLowerCase().includes('barber') || identifier.toLowerCase().includes('erick') || identifier.toLowerCase().includes('carlos') || cleanPhone.endsWith('1111') || cleanPhone.endsWith('2222')) {
            role = 'barber';
          } else {
            role = 'client';
          }
        }

        let name = 'Usuário';

        if (role === 'barber') {
          name = 'Erick Machado';
          currentSession = {
            id: 'barber-1',
            role: 'barber',
            name: name,
            email: identifier.includes('@') ? identifier : 'erick@honoratobarber.com',
            phone: input.phone || '(34) 99999-1111',
            barbearia_id: 'default'
          };
        } else if (role === 'admin') {
          name = 'Honorato (Gerente)';
          currentSession = {
            id: 'admin-1',
            role: 'admin',
            name: name,
            email: identifier.includes('@') ? identifier : 'admin@honoratobarber.com',
            phone: input.phone || '(34) 99999-0000',
            barbearia_id: 'default'
          };
        } else {
          name = 'Cliente Honorato';
          currentSession = {
            id: 'client-1',
            role: 'client',
            name: name,
            email: identifier.includes('@') ? identifier : 'cliente@gmail.com',
            phone: input.phone || '(34) 99318-4075',
            barbearia_id: 'default'
          };
        }

        return sendJson({
          success: true,
          session: currentSession
        });
      });
    }

    if (route === 'logout') {
      currentSession = null;
      return sendJson({ success: true });
    }

    if (route === 'barbers' || route.startsWith('barbers/')) {
      if (req.method === 'PUT') {
        const id = route.replace(/^barbers\/?/, '');
        return readBody(input => {
          const idx = db.barbers.findIndex(b => b.id === id || b.id === input.id);
          if (idx !== -1) {
            db.barbers[idx] = { ...db.barbers[idx], ...input };
          }
          return sendJson({ success: true, barber: db.barbers[idx] });
        });
      }
      return sendJson(db.barbers);
    }

    if (route === 'service-catalog' || route.startsWith('service-catalog/')) {
      if (req.method === 'PUT') {
        const id = route.replace(/^service-catalog\/?/, '');
        return readBody(input => {
          const idx = db.serviceCatalog.findIndex(s => s.id === id || s.id === input.id);
          if (idx !== -1) {
            db.serviceCatalog[idx] = { ...db.serviceCatalog[idx], ...input };
            return sendJson({ success: true, item: db.serviceCatalog[idx] });
          }
          return sendJson({ error: 'Item not found' }, 404);
        });
      }
      if (req.method === 'POST') {
        return readBody(input => {
          const item = { id: input.id || 'svc-' + Date.now(), ...input };
          db.serviceCatalog.push(item);
          return sendJson({ success: true, item });
        });
      }
      if (req.method === 'DELETE') {
        const id = route.replace(/^service-catalog\/?/, '');
        db.serviceCatalog = db.serviceCatalog.filter(s => s.id !== id);
        return sendJson({ success: true });
      }
      return sendJson(db.serviceCatalog);
    }

    if (route === 'gallery' || route.startsWith('gallery/')) {
      if (req.method === 'POST') {
        return readBody(input => {
          const item = { id: input.id || 'gal-' + Date.now(), ...input };
          db.gallery = db.gallery || [];
          db.gallery.unshift(item);
          return sendJson({ success: true, item });
        });
      }
      if (req.method === 'DELETE') {
        const id = route.replace(/^gallery\/?/, '');
        db.gallery = (db.gallery || []).filter(g => g.id !== id);
        return sendJson({ success: true });
      }
      if (req.method === 'PUT') {
        const id = route.replace(/^gallery\/?/, '');
        return readBody(input => {
          const idx = (db.gallery || []).findIndex(g => g.id === id || g.id === input.id);
          if (idx !== -1) {
            db.gallery[idx] = { ...db.gallery[idx], ...input };
            return sendJson({ success: true, item: db.gallery[idx] });
          }
          return sendJson({ error: 'Item not found' }, 404);
        });
      }
      return sendJson(db.gallery || []);
    }

    if (route === 'bookings' || route.startsWith('bookings/')) {
      if (req.method === 'PUT') {
        const id = route.replace(/^bookings\/?/, '');
        return readBody(input => {
          const idx = db.bookings.findIndex(b => b.id === id || b.id === input.id);
          if (idx !== -1) {
            db.bookings[idx] = { ...db.bookings[idx], ...input };
            return sendJson({ success: true, booking: db.bookings[idx] });
          }
          return sendJson({ error: 'Agendamento não encontrado' }, 404);
        });
      }
      if (req.method === 'POST') {
        return readBody(input => {
          const newBooking = { ...input, id: input.id || `book-${Date.now()}` };
          db.bookings.push(newBooking);
          return sendJson({ success: true, booking: newBooking });
        });
      }
      return sendJson(db.bookings);
    }

    if (route === 'clients') {
      if (req.method === 'POST') {
        return readBody(input => {
          db.clients.push(input);
          return sendJson({ success: true, id: input.id });
        });
      }
      return sendJson(db.clients);
    }

    if (route === 'payment-settings') {
      return sendJson(db.paymentSettings);
    }

    if (route === 'products') {
      return sendJson(db.products);
    }

    if (route === 'drinks') {
      return sendJson(db.drinks);
    }

    if (route === 'subscription-plans') {
      return sendJson(db.subscriptionPlans);
    }

    if (route === 'orders') {
      return sendJson([]);
    }

    if (route === 'admins') {
      return sendJson([]);
    }

    if (route === 'commission-payments') {
      return sendJson({});
    }

    return sendJson({ error: 'Endpoint não implementado no mock local' }, 404);
  }

  // ----------------------------------------------------
  // STATIC ASSETS & SPA ROUTING
  // ----------------------------------------------------
  let targetPath = decodeURI(reqPath);
  if (targetPath === '/') targetPath = '/index.html';
  let filePath = path.join(__dirname, targetPath);

  // If path doesn't exist and has no extension, fallback to index.html (SPA)
  if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
    if (!path.extname(targetPath)) {
      filePath = path.join(__dirname, 'index.html');
    } else {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('404 Not Found');
      return;
    }
  }

  const ext = path.extname(filePath).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  fs.readFile(filePath, (err, content) => {
    if (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end('500 Server Error');
      return;
    }
    res.writeHead(200, { 'Content-Type': contentType });
    res.end(content);
  });
}

const server = http.createServer(handleRequest);

server.listen(PORT, '0.0.0.0', () => {
  console.log(`\n======================================================`);
  console.log(`🚀 Site Honorato Barber rodando com sucesso!`);
  console.log(`👉 No Navegador: http://localhost:${PORT}`);
  console.log(`👉 IP Direto: http://127.0.0.1:${PORT}`);
  console.log(`👉 No Celular / Wi-Fi: http://192.168.18.57:${PORT}`);
  console.log(`⭐ Painel do Barbeiro: http://localhost:${PORT}/?as=barber`);
  console.log(`⭐ Painel do Administrador: http://localhost:${PORT}/?as=admin`);
  console.log(`======================================================\n`);
});

// Also listen on default HTTP port 80 (so user can type just 'http://localhost' without port)
try {
  const server80 = http.createServer(handleRequest);
  server80.listen(80, '0.0.0.0', () => {
    console.log(`👉 Também ativo na porta 80 padrão: http://localhost`);
  }).on('error', () => {});
} catch (e) {}

// Also listen on 5173 (standard Vite dev port)
try {
  const server5173 = http.createServer(handleRequest);
  server5173.listen(5173, '0.0.0.0', () => {
    console.log(`👉 Também ativo na porta 5173: http://localhost:5173`);
  }).on('error', () => {});
} catch (e) {}
