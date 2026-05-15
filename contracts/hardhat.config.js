require("dotenv").config({ override: true });
require("@nomicfoundation/hardhat-toolbox");

// Falla explícitamente si faltan las claves — nunca usar fallbacks hardcodeados
function requireEnv(name) {
  const val = process.env[name];
  if (!val) throw new Error(`❌ Variable de entorno faltante: ${name}. Defínela en contracts/.env`);
  return val;
}

// En tareas que no necesitan cuentas (compile, test con hardhat network) no forzamos las claves
const isDeployTask = process.argv.some(a => ["run", "deploy", "ignition"].includes(a));

/** @type import('hardhat/config').HardhatUserConfig */
module.exports = {
  solidity: {
    version: "0.8.28",
    settings: {
      evmVersion: "cancun",
      optimizer: {
        enabled: true,
        runs: 200,
      },
    },
  },
  networks: {
    besu: {
      url: process.env.BESU_RPC_URL || "http://localhost:8545",
      chainId: 1337,
      accounts: [
        requireEnv("DEPLOYER_PRIVATE_KEY"),
        requireEnv("BESU_PRIVATE_KEY_1"),
        requireEnv("BESU_PRIVATE_KEY_2"),
        requireEnv("BESU_PRIVATE_KEY_3"),
      ],
      gas: 8000000,
      gasPrice: 0,
      timeout: 60000,
    },
  },
};