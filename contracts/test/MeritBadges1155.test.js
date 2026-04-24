const { expect } = require("chai");
const { ethers } = require("hardhat");

describe("MeritBadges1155", function () {
  let badges;
  let admin, issuer, student, stranger;

  const BADGE_ID = 1001;
  const BADGE_URI = "ipfs://QmFakeCID123/badge-completion.json";

  beforeEach(async function () {
    [admin, issuer, student, stranger] = await ethers.getSigners();

    const Badges = await ethers.getContractFactory("MeritBadges1155");
    badges = await Badges.deploy(admin.address);
    await badges.waitForDeployment();

    // Otorgar ISSUER_ROLE al issuer (admin ya lo tiene por constructor)
    const ISSUER_ROLE = await badges.ISSUER_ROLE();
    await badges.connect(admin).grantRole(ISSUER_ROLE, issuer.address);
  });

  // ── Test 1: Emitir insignia con ISSUER_ROLE → debe pasar ─────────
  describe("Emisión con ISSUER_ROLE", function () {
    it("debe emitir una insignia correctamente", async function () {
      await badges
        .connect(issuer)
        .mintBadge(student.address, BADGE_ID, BADGE_URI);

      expect(await badges.balanceOf(student.address, BADGE_ID)).to.equal(1);
      expect(await badges.uri(BADGE_ID)).to.equal(BADGE_URI);
      expect(await badges.isMinted(student.address, BADGE_ID)).to.be.true;
    });

    it("debe emitir el evento BadgeMinted", async function () {
      await expect(
        badges.connect(issuer).mintBadge(student.address, BADGE_ID, BADGE_URI)
      )
        .to.emit(badges, "BadgeMinted")
        .withArgs(student.address, BADGE_ID, BADGE_URI);
    });
  });

  // ── Test 2: Emitir insignia sin ISSUER_ROLE → debe fallar ────────
  describe("Emisión sin ISSUER_ROLE", function () {
    it("debe rechazar si no tiene ISSUER_ROLE", async function () {
      await expect(
        badges
          .connect(stranger)
          .mintBadge(student.address, BADGE_ID, BADGE_URI)
      ).to.be.reverted;
    });
  });

  // ── Test 3: Idempotencia — no emitir dos veces al mismo estudiante ─
  describe("Idempotencia", function () {
    it("debe revertir si la insignia ya fue emitida al mismo estudiante", async function () {
      await badges
        .connect(issuer)
        .mintBadge(student.address, BADGE_ID, BADGE_URI);

      await expect(
        badges
          .connect(issuer)
          .mintBadge(student.address, BADGE_ID, BADGE_URI)
      )
        .to.be.revertedWithCustomError(badges, "BadgeAlreadyMinted")
        .withArgs(student.address, BADGE_ID);
    });

    it("debe permitir la misma insignia a estudiantes diferentes", async function () {
      await badges
        .connect(issuer)
        .mintBadge(student.address, BADGE_ID, BADGE_URI);

      await badges
        .connect(issuer)
        .mintBadge(stranger.address, BADGE_ID, BADGE_URI);

      expect(await badges.balanceOf(student.address, BADGE_ID)).to.equal(1);
      expect(await badges.balanceOf(stranger.address, BADGE_ID)).to.equal(1);
    });
  });

  // ── Test 4: Pausable ──────────────────────────────────────────────
  describe("Pausable", function () {
    it("debe pausar y bloquear emisiones", async function () {
      await badges.connect(admin).pause();

      await expect(
        badges.connect(issuer).mintBadge(student.address, BADGE_ID, BADGE_URI)
      ).to.be.reverted;
    });

    it("debe permitir emisiones tras despausar", async function () {
      await badges.connect(admin).pause();
      await badges.connect(admin).unpause();

      await badges
        .connect(issuer)
        .mintBadge(student.address, BADGE_ID, BADGE_URI);

      expect(await badges.balanceOf(student.address, BADGE_ID)).to.equal(1);
    });

    it("solo admin puede pausar", async function () {
      await expect(badges.connect(stranger).pause()).to.be.reverted;
    });
  });
});
