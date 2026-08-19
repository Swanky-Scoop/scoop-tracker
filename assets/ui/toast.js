///////////////////////////////////
// Notification mechanism
// shows result of posts
// designed GIRD forms in mind
//////////////////////////////////

export default class Toast {
  static _el(tag, text='', ...classes) {
    const n = document.createElement(tag);
    if (classes.length) n.classList.add(...classes);
    if(text.length > 0) n.append(text);
    return n;
  }
  static _ensureHost(){
    let TOASTER = document.querySelector('body > .TOASTER'); 
    if(TOASTER) return TOASTER;
    
    TOASTER         = Toast._el('div', '', 'TOASTER');
    const SCROLLER  = Toast._el('div', '', 'SCROLLER');
    const TOGGLER   = Toast._el('button', 'x', 'TOGGLER');
    TOGGLER.addEventListener('click', (e)=>{
      TOASTER.classList.toggle('show');
    });
    TOASTER.append(TOGGLER);
    TOASTER.append(SCROLLER);
    document.body.append(TOASTER);

    return TOASTER;
  }

  static addMessage({title='title', message='', changes=[], state='OK', id=null}={}){
    const date    = Date.now();
    const TOASTER = Toast._ensureHost();
    const TOAST   = Toast._el('div', '', 'TOAST', state, 't'+date);
    const H3      = Toast._el('h3', title);
    const P       = Toast._el('p', message);
    const ul      = Toast._el('ul');
    const DATE    = Toast._el('spen', new Date(date).toLocaleString(), 'date' );
    const CLOSE   = Toast._el('button', 'x', 'close');

    CLOSE.addEventListener('click', (e)=>{
      e.target.closest('.TOAST').remove();
      Toast._hideIfEmpty();
    });

    changes.forEach(element => {
      ul.append(Toast._el('li', element.sentence));
    });

    TOAST.append(DATE);
    TOAST.append(H3);
    TOAST.append(P);
    TOAST.append(ul);
    TOAST.append(CLOSE);
    TOAST.update = Toast.update;
    if(id) TOAST.id = id;
    TOASTER.querySelector('.SCROLLER').append(TOAST);
    TOASTER.classList.add('show');

    // Fades out on its own after a few seconds (see the .expired rule in
    // css.css) — deliberately NOT removed from the DOM, unlike the close
    // button's handler above. Keeping it around is what a future "review
    // session activity" feature would read from.
    setTimeout(() => {
      TOAST.classList.add('expired');
      Toast._hideIfEmpty();
    }, 4000);

    return TOAST;

  }

  // Retracts TOASTER (see .TOASTER.show in css.css) the moment nothing live
  // is left in it — called after both ways a toast can stop being "live"
  // (auto-expiring above, or the close button's own handler). Without this,
  // .show stuck applied forever after the very first toast was the actual
  // bug: an invisible (background:transparent) but still on-screen,
  // z-index:1000000 container that outlived every toast inside it.
  // .expired cards are left in the DOM on purpose (see addMessage's own
  // comment) so this checks liveness, not presence.
  static _hideIfEmpty(){
    const TOASTER = Toast._ensureHost();
    const stillLive = TOASTER.querySelector('.SCROLLER')?.querySelector('.TOAST:not(.expired)');
    if (!stillLive) Toast.hide();
  }

  static update(node, {title='title', message='This event happened', state='OK'} = {}){
    if(!(node instanceof Element)) return;
    const date    = Date.now();
    node.querySelector('h3')   .textContent = title;
    node.querySelector('p')    .textContent = message;
    node.querySelector('.date').textContent = new Date(date).toLocaleString();
  }

  static hide(){
    Toast._ensureHost().classList.remove('show');
  }
  static show(){
    Toast._ensureHost().classList.add('show');
  }
  static empty(){
    Toast._ensureHost().replaceChildren();
  }

}