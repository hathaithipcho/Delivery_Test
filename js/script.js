(function () {
  'use strict';

  var header = document.getElementById('top');
  var ticking = false;
  function onScroll() {
    header.classList.toggle('solid', (window.scrollY || 0) > 40);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
  }, { passive: true });
  onScroll();

  var items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.1 });
    items.forEach(function (el) { io.observe(el); });
  } else {
    items.forEach(function (el) { el.classList.add('in'); });
  }
})();

(function () {
  'use strict';

  var reduce = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hero = document.getElementById('hero');

  /* A. Scroll progress bar + hero parallax */
  var bar = document.createElement('div');
  bar.className = 'progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);

  var heroBg    = document.getElementById('heroBg');
  var sceneEl   = document.querySelector('.scene');
  var roadEl    = document.querySelector('.road');
  var contentEl = document.querySelector('.hero-content');

  var heroH = hero ? hero.offsetHeight : 0;
  var ticking = false;

  function update() {
    var y = window.scrollY || 0;
    var max = document.documentElement.scrollHeight - window.innerHeight;
    bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(y / max, 1) : 0) + ')';

    if (hero && !reduce && y <= heroH) {
      var t = function (k) { return 'translate3d(0,' + (y * k).toFixed(1) + 'px,0)'; };
      if (heroBg)    heroBg.style.transform = t(0.3);
      if (sceneEl)   sceneEl.style.transform = t(0.3);
      if (roadEl)    roadEl.style.transform = t(0.12);
      if (contentEl) {
        contentEl.style.transform = t(0.2) + ' scale(.9)';
        contentEl.style.opacity = Math.max(0, 1 - y / (heroH * 0.6)).toFixed(3);
      }
    }
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; requestAnimationFrame(update); }
  }, { passive: true });
  window.addEventListener('resize', function () {
    heroH = hero ? hero.offsetHeight : 0;
    update();
  });
  update();

  if (hero && !reduce) {
    var wrap = document.createElement('div');
    wrap.className = 'petals';
    wrap.setAttribute('aria-hidden', 'true');

    var rand = function (a, b) { return a + Math.random() * (b - a); };
    var count = window.innerWidth < 820 ? 8 : 14;

    for (var i = 0; i < count; i++) {
      var p = document.createElement('i');
      p.style.setProperty('--x', rand(0, 100).toFixed(1) + '%');
      p.style.setProperty('--s', rand(10, 20).toFixed(1) + 'px');
      p.style.setProperty('--d', rand(9, 16).toFixed(1) + 's');
      p.style.setProperty('--dl', '-' + rand(0, 16).toFixed(1) + 's'); /* ติดลบ = เริ่มมาก็ร่วงอยู่แล้ว */
      p.style.setProperty('--sw', rand(-60, 60).toFixed(0) + 'px');
      p.style.setProperty('--r', rand(120, 360).toFixed(0) + 'deg');
      wrap.appendChild(p);
    }
    hero.insertBefore(wrap, hero.querySelector('.road'));

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        wrap.classList.toggle('paused', !entries[0].isIntersecting);
      }).observe(hero);
    }
  }

  document.querySelectorAll('.orn').forEach(function (o) {
    var nodes = o.childNodes;
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].nodeType === 3 && nodes[i].textContent.trim()) {
        var s = document.createElement('span');
        s.className = 'orn-mark';
        s.textContent = nodes[i].textContent.trim();
        o.replaceChild(s, nodes[i]);
        break;
      }
    }
  });

  var h2 = document.querySelector('.title h2');
  if (h2 && !reduce) {
    var text = h2.textContent.trim();
    var idx = 0;
    h2.setAttribute('aria-label', text);
    h2.textContent = '';
    text.split(' ').forEach(function (word, wi, arr) {
      var w = document.createElement('span');
      w.className = 'w';
      w.setAttribute('aria-hidden', 'true');
      word.split('').forEach(function (ch) {
        var c = document.createElement('span');
        c.className = 'ch';
        c.style.setProperty('--c', idx++);
        c.textContent = ch;
        w.appendChild(c);
      });
      h2.appendChild(w);
      if (wi < arr.length - 1) h2.appendChild(document.createTextNode(' '));
    });
  }
})();

(function () {
  'use strict';
  var scene = document.querySelector('.scene');
  var hero = document.getElementById('hero');
  if (!scene || !hero || !('IntersectionObserver' in window)) return;
  new IntersectionObserver(function (entries) {
    scene.classList.toggle('paused', !entries[0].isIntersecting);
  }).observe(hero);
})();

(function () {
  'use strict';
  var hero = document.getElementById('hero');
  if (!hero || !('IntersectionObserver' in window)) return;
  new IntersectionObserver(function (entries) {
    hero.classList.toggle('is-off', !entries[0].isIntersecting);
  }).observe(hero);
})();

