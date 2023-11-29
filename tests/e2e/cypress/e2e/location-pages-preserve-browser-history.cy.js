describe("location search", () => {
  it("properly handles browser history", () => {
    const start = "/local/OHX/50/57/Nashville";

    cy.visit(start);
    cy.get("form[data-location-search] input").type("Atlanta", { delay: 200 });
    cy.get("form[data-location-search] ul li").first().click();
    cy.location("pathname").should("not.equal", start);

    cy.go("back");
    cy.location("pathname").should("equal", start);
  });
});