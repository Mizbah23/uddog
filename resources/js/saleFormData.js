// A sale needs the catalog and contacts, not the workspace's reporting/history data.
export function createSaleFormLoader(api) {
  let request = null;
  return {
    invalidate() { request = null; },
    load(branchId, { goldStore = false } = {}) {
      const key = `${branchId}:${goldStore}`;
      if (request?.key === key) return request.promise;
      const pending = { key, promise: null };
      pending.promise = Promise.all([
        api(`products?branch_id=${encodeURIComponent(branchId)}`),
        api('contacts'),
        goldStore ? api(`gold/rates?branch_id=${encodeURIComponent(branchId)}`) : Promise.resolve([]),
      ]).then(([products, contacts, goldRates]) => ({ products, contacts, goldRates })).catch(error => {
        if (request === pending) request = null;
        throw error;
      });
      request = pending;
      return pending.promise;
    },
  };
}
