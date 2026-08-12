/**
 * Module resolve hook: 'ethers' importunu izole test stub'ına yönlendirir.
 * Böylece testler gerçek ethers paketi kurulu olmadan da çalışır.
 */
export async function resolve(specifier, context, next) {
  if (specifier === 'ethers') {
    return {
      url: new URL('./ethers-stub.mjs', import.meta.url).href,
      shortCircuit: true,
    };
  }
  return next(specifier, context);
}
