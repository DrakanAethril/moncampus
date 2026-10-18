/* Formatool — serious games — the game's side of the SDK (design/validated/serious-games.md §6).

   A game runs in a sandboxed frame with an opaque origin; it reaches the platform only through its
   host page, by postMessage. This classic script sets globalThis.SGSdk:

     SGSdk.call(method, params) → Promise of the platform's JSON (rejected with the error code)
       getUser() · getProgress() · saveProgress({data}) · startSeries({unit}) · startFinal()
       studyComet({misconception}) · nextQuestion({session}) · answer({session, input})
       endSeries / endFinal / endComet({session})
     SGSdk.onReward(fn)  - fn(rewards) when a call granted collection pieces (play the scene)
     SGSdk.exit()        - back to the catalogue

   The platform generates and corrects: a question comes without its answer, and during the final
   test an answer gets no correction before the end. Nothing here may decide what a player has
   acquired - saveProgress() keeps the game's display state only. */
(function (root) {
  'use strict';
  var pending = {}, next = 1, rewardListeners = [];

  root.addEventListener('message', function (event) {
    if (event.source !== root.parent) return;
    var m = event.data;
    if (!m || m.sg !== 1) return;
    if (m.type === 'result' && pending[m.id]) {
      var p = pending[m.id]; delete pending[m.id];
      if (m.ok) p.resolve(m.result); else p.reject(new Error(m.error || 'error'));
    } else if (m.type === 'reward.granted' && Array.isArray(m.rewards)) {
      rewardListeners.forEach(function (fn) { try { fn(m.rewards); } catch (e) { /* a listener's error is its own */ } });
    }
  });

  root.SGSdk = {
    call: function (method, params) {
      var id = next++;
      return new Promise(function (resolve, reject) {
        pending[id] = { resolve: resolve, reject: reject };
        root.parent.postMessage({ sg: 1, type: 'call', id: id, method: method, params: params || {} }, '*');
      });
    },
    onReward: function (fn) { rewardListeners.push(fn); },
    exit: function () { root.parent.postMessage({ sg: 1, type: 'exit' }, '*'); }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
