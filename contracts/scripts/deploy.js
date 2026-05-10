/**
 * MeritCoin — Script de despliegue
 *
 * Despliega:
 *   1. MeritBadges1155  (ERC-1155 — insignias)
 *   2. MeritCoinERC20   (ERC-20  — token MRT)
 *
 * Uso:
 *   # Terminal 1: levantar nodo local
 *   npx hardhat node
 *
 *   # Terminal 2: desplegar
 *   npx hardhat run scripts/deploy.js --network localhost
 */

const hre = require("hardhat");

async function main() {
  const [deployer] = await hre.ethers.getSigners();
  console.log("Desplegando contratos con la cuenta:", deployer.address);
  console.log(
    "Balance:",
    hre.ethers.formatEther(
      await hre.ethers.provider.getBalance(deployer.address)
    ),
    "ETH"
  );
  console.log("---");

  // ── 1. MeritBadges1155 ──────────────────────────────────────────────
  const Badges = await hre.ethers.getContractFactory("MeritBadges1155");
  const badges = await Badges.deploy(deployer.address, {
    gasLimit: 8000000,
    gasPrice: 1000
  });
  await badges.waitForDeployment();
  const badgesAddr = await badges.getAddress();
  console.log("MeritBadges1155 desplegado en:", badgesAddr);

  // ── 2. MeritCoinERC20 ──────────────────────────────────────────────
  const Token = await hre.ethers.getContractFactory("MeritCoinERC20");
  const token = await Token.deploy(deployer.address, {
    gasLimit: 8000000,
    gasPrice: 1000
  });
  await token.waitForDeployment();
  const tokenAddr = await token.getAddress();
  console.log("MeritCoinERC20  desplegado en:", tokenAddr);

  // ── Resumen ─────────────────────────────────────────────────────────
  console.log("\n=== Resumen de despliegue ===");
  console.log(`BADGE_CONTRACT_ADDRESS=${badgesAddr}`);
  console.log(`MRT_CONTRACT_ADDRESS=${tokenAddr}`);
  console.log("\nCopia estas direcciones a tu archivo .env");
}

main()
  .then(() => process.exit(0))
  .catch((error) => {
    console.error(error);
    process.exit(1);
  });
