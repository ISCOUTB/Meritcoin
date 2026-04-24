const { expect } = require("chai");
const { ethers } = require("hardhat");

describe("MeritCoinERC20", function () {
  let token;
  let admin, minter, student, stranger;

  const MINT_AMOUNT = ethers.parseEther("100"); // 100 MRT

  beforeEach(async function () {
    [admin, minter, student, stranger] = await ethers.getSigners();

    const Token = await ethers.getContractFactory("MeritCoinERC20");
    token = await Token.deploy(admin.address);
    await token.waitForDeployment();

    const MINTER_ROLE = await token.MINTER_ROLE();
    await token.connect(admin).grantRole(MINTER_ROLE, minter.address);
  });

  // ── Metadata ──────────────────────────────────────────────────────
  describe("Metadata del token", function () {
    it("debe tener nombre MeritCoin", async function () {
      expect(await token.name()).to.equal("MeritCoin");
    });

    it("debe tener símbolo MRT", async function () {
      expect(await token.symbol()).to.equal("MRT");
    });

    it("debe tener 18 decimales", async function () {
      expect(await token.decimals()).to.equal(18);
    });
  });

  // ── Test 1: Mint con MINTER_ROLE → debe pasar ────────────────────
  describe("Mint con MINTER_ROLE", function () {
    it("debe acuñar tokens correctamente", async function () {
      await token.connect(minter).mint(student.address, MINT_AMOUNT);

      expect(await token.balanceOf(student.address)).to.equal(MINT_AMOUNT);
      expect(await token.totalSupply()).to.equal(MINT_AMOUNT);
    });

    it("debe emitir el evento TokensMinted", async function () {
      await expect(token.connect(minter).mint(student.address, MINT_AMOUNT))
        .to.emit(token, "TokensMinted")
        .withArgs(student.address, MINT_AMOUNT);
    });

    it("debe acumular múltiples mints", async function () {
      await token.connect(minter).mint(student.address, MINT_AMOUNT);
      await token.connect(minter).mint(student.address, MINT_AMOUNT);

      expect(await token.balanceOf(student.address)).to.equal(
        MINT_AMOUNT * 2n
      );
    });
  });

  // ── Test 2: Mint sin MINTER_ROLE → debe fallar ───────────────────
  describe("Mint sin MINTER_ROLE", function () {
    it("debe rechazar si no tiene MINTER_ROLE", async function () {
      await expect(
        token.connect(stranger).mint(student.address, MINT_AMOUNT)
      ).to.be.reverted;
    });
  });

  // ── Test 3: Pausable ──────────────────────────────────────────────
  describe("Pausable", function () {
    it("debe pausar y bloquear mints", async function () {
      await token.connect(admin).pause();

      await expect(
        token.connect(minter).mint(student.address, MINT_AMOUNT)
      ).to.be.reverted;
    });

    it("debe pausar y bloquear transferencias", async function () {
      await token.connect(minter).mint(student.address, MINT_AMOUNT);
      await token.connect(admin).pause();

      await expect(
        token
          .connect(student)
          .transfer(stranger.address, ethers.parseEther("10"))
      ).to.be.reverted;
    });

    it("debe permitir mints tras despausar", async function () {
      await token.connect(admin).pause();
      await token.connect(admin).unpause();

      await token.connect(minter).mint(student.address, MINT_AMOUNT);
      expect(await token.balanceOf(student.address)).to.equal(MINT_AMOUNT);
    });

    it("solo admin puede pausar", async function () {
      await expect(token.connect(stranger).pause()).to.be.reverted;
    });
  });
});
