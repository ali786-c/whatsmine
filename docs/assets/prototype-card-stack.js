// Motion inspired by Skiper UI 17 StickyCard_002, @gurvinder-singh02.
// Reference creator: https://gxuri.me — standalone adaptation for WhatsMine.
(() => {
  const section = document.getElementById('product-story');
  if (!section) return;
  section.classList.add('stack-story');
  const items = [
    ['dashboard', 'Dashboard', 'See your business clearly.', 'Track message activity, conversations and team performance from one clear overview.'],
    ['inbox', 'Unified Inbox', 'Bring every conversation together.', 'Manage customer conversations across your connected channels, with context and your team in one shared inbox.'],
    ['automation', 'Automation Builder', 'Make the next step happen.', 'Build connected workflows for order confirmations, customer replies and follow-ups with a visual automation builder.'],
    ['templates', 'Message Templates', 'Make every message thoughtful.', 'Create and organize WhatsApp templates for useful updates, order notifications and your next campaign.'],
  ];
  const product = section.querySelector('.story-product');
  const deck = document.createElement('div');
  deck.className = 'stack-deck';
  deck.innerHTML = items.map(([view, name, title, description], i) => `<article class="stack-card" data-product="${view}" style="z-index:${i + 1}"><div class="stack-card-bar"><b>WHATSMINE / ${name}</b><small>0${i + 1} / 04</small></div><div class="stack-card-content"><div class="stack-card-copy"><span class="stack-card-label">ONE CONNECTED WORKSPACE</span><h2>${title}</h2><p>${description}</p><span class="stack-card-hint">Scroll to explore <span aria-hidden="true">?</span></span></div><div class="stack-card-preview"><div class="stack-preview-title"><span>${name}</span><small>DEMO WORKSPACE</small></div><button class="screen-display" data-view="${view}" aria-label="Expand ${name} demo screenshot"></button></div></div><div class="stack-card-footer"><span>Conversations. Context. Next steps.</span><span>${name}</span></div></article>`).join('');
  product.replaceWith(deck);
  section.querySelector('.story-copy')?.remove();
  section.querySelector('.flow-ribbon')?.remove();
  const cards = Array.from(deck.querySelectorAll('.stack-card'));
  const chapters = Array.from(section.querySelectorAll('.story-step'));
  const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
  const layoutPreference = matchMedia('(min-width: 761px) and (min-height: 580px)');
  let context = null, timeline = null, active = -1;
  const activate = index => {
    if (active === index) return;
    active = index;
    chapters.forEach((button, i) => {
      button.classList.toggle('active', i === index);
      button.setAttribute('aria-pressed', String(i === index));
    });
    cards.forEach((card, i) => {
      card.inert = i !== index && !!timeline;
      card.querySelector('button').tabIndex = i === index || !timeline ? 0 : -1;
    });

  };
  cards.forEach(card => card.querySelector('button').addEventListener('click', () => document.querySelector('.image-dialog').showModal()));
  const teardown = () => {
    if (context) context.revert();
    context = timeline = null;
    cards.forEach(card => { card.style.removeProperty('transform'); card.inert = false; card.querySelector('button').tabIndex = 0; });
    section.classList.remove('stack-animated');
    active = -1;
  };
  const setup = () => {
    teardown();
    if (!window.gsap || !window.ScrollTrigger || motionPreference.matches || !layoutPreference.matches || document.body.classList.contains('motion-paused')) {
      activate(0);
      return;
    }
    gsap.registerPlugin(ScrollTrigger);
    section.classList.add('stack-animated');
    context = gsap.context(() => {
      gsap.set(cards[0], { yPercent: 0, scale: 1, rotation: 0 });
      gsap.set(cards.slice(1), { yPercent: 110, scale: 1, rotation: 0 });
      timeline = gsap.timeline({
        onUpdate() {
          const progress = this.progress();
          activate(Math.min(cards.length - 1, Math.round(progress * (cards.length - 1))));

        },
        scrollTrigger: {
          id: 'whatsmine-card-stack', trigger: section,
          start: 'top 64px', end: () => `+=${innerHeight * (cards.length - 1)}`,
          pin: section.querySelector('.story-pin'), scrub: 0.5, pinSpacing: true,
          anticipatePin: 1, invalidateOnRefresh: true,
        },
      });
      for (let i = 0; i < cards.length - 1; i++) {
        timeline.to(cards[i], { scale: 0.9, rotation: 3, duration: 1, ease: 'none' }, i);
        timeline.to(cards[i + 1], { yPercent: 0, duration: 1, ease: 'none' }, i);
      }
      activate(0);
    }, section);
  };
  chapters.forEach((button, index) => button.addEventListener('click', () => {
    if (timeline?.scrollTrigger) {
      const trigger = timeline.scrollTrigger;
      const top = trigger.start + (trigger.end - trigger.start) * index / (cards.length - 1);
      scrollTo({ top, behavior: 'smooth' });
    } else {
      activate(index);
      cards[index].scrollIntoView({ block: 'center', behavior: motionPreference.matches ? 'instant' : 'smooth' });
    }
  }));
  setup();
  motionPreference.addEventListener('change', setup);
  layoutPreference.addEventListener('change', setup);
  const observer = new MutationObserver(setup);
  observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  const refresh = () => { if (timeline) timeline.scrollTrigger.refresh(); };
  addEventListener('load', refresh);
  document.fonts?.ready.then(refresh);
  addEventListener('pagehide', () => {
    observer.disconnect(); teardown();
    motionPreference.removeEventListener('change', setup);
    layoutPreference.removeEventListener('change', setup);
    removeEventListener('load', refresh);
  }, { once: true });
})();
