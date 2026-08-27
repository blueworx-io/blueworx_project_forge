import { createHash, createHmac, randomBytes } from 'node:crypto';

// ARCH-6's canonical form, for tests that need to speak as a client site.
//
// A client site proves who it is with a signature rather than with a login, so
// anything a spec wants to attempt *as one* has to be signed — and the specs
// that matter most are the ones attempting things a client site must not be
// allowed to do. A refusal is only worth anything if the request was otherwise
// perfectly formed.
//
// **This is a third copy of the canonical form**, after the studio's
// includes/Rest/Signature.php and the client plugin's Signer. The first two are
// held together by tests/php/SignerConformanceTest, which signs the same inputs
// with both and fails if they differ. This one is not covered by that test, and
// it does not need to be: it is proved by every request it makes. A signing
// helper that has drifted signs nothing the studio will accept, so the specs
// using it fail immediately and loudly rather than quietly passing.
//
// It lives here, above both suites, so the single-instance and two-instance
// suites share it rather than growing a fourth copy and a fifth.

const NAMESPACE = '/blueworx-forge/v1';

/**
 * The headers one signed request carries.
 *
 * The path signed is the studio's REST route, not the full URL — the same thing
 * the studio verifies against, and the only thing that survives a proxy
 * rewriting the front of it.
 */
export function signedHeaders(key, siteId, method, route, body = '') {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = randomBytes(16).toString('hex');

  const canonical = [
    siteId,
    method.toUpperCase(),
    `${NAMESPACE}${route}`,
    timestamp,
    nonce,
    createHash('sha256').update(body).digest('hex'),
  ].join('\n');

  return {
    'X-BWX-Site': siteId,
    'X-BWX-Timestamp': timestamp,
    'X-BWX-Nonce': nonce,
    'X-BWX-Signature': createHmac('sha256', key).update(canonical).digest('hex'),
    'Content-Type': 'application/json',
  };
}

/**
 * A caller that speaks as one client site.
 *
 * `replay` is the reason this returns headers as well as responses: proving a
 * captured request cannot be used twice (D-9, D-26) means sending the very same
 * headers again, which is not something a caller that signs afresh each time
 * can do.
 */
export function asSite(request, key, siteId) {
  const url = (route) => `/wp-json${NAMESPACE}${route}`;

  // The studio verifies against the matched route, which carries no query
  // string. Signing one in would produce a request that fails to verify for no
  // visible reason — and, worse here, would make a spec proving that a filter
  // cannot widen the answer pass because the request was rejected instead.
  const pathOf = (route) => route.split('?')[0];

  return {
    key,
    siteId,

    headers: (method, route, body = '') => signedHeaders(key, siteId, method, pathOf(route), body),

    get: (route) =>
      request.get(url(route), { headers: signedHeaders(key, siteId, 'GET', pathOf(route)) }),

    post: (route, data) => {
      const body = JSON.stringify(data);

      return request.post(url(route), {
        headers: signedHeaders(key, siteId, 'POST', pathOf(route), body),
        data: body,
      });
    },

    // Here so the denial suite can reach for the studio's edit routes with a
    // valid client signature. Nothing the client artifact does is a PATCH —
    // that is the point of being able to try one.
    patch: (route, data) => {
      const body = JSON.stringify(data);

      return request.patch(url(route), {
        headers: signedHeaders(key, siteId, 'PATCH', pathOf(route), body),
        data: body,
      });
    },

    /** The same request twice, with the same signature on both. */
    replay: async (method, route, data) => {
      const body = undefined === data ? '' : JSON.stringify(data);
      const headers = signedHeaders(key, siteId, method, pathOf(route), body);
      const send = () =>
        'GET' === method.toUpperCase()
          ? request.get(url(route), { headers })
          : request.post(url(route), { headers, data: body });

      return { first: await send(), second: await send() };
    },
  };
}
